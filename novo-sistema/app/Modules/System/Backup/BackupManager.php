<?php

namespace App\Modules\System\Backup;

use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use SQLite3;
use Throwable;
use ZipArchive;

/**
 * Copia de seguranca da instalacao (Fase 13; operacao.md).
 *
 * Um arquivo .zip por copia com:
 *   - database.sqlite: copia CONSISTENTE do banco pela API de backup do SQLite
 *     (funciona com o sistema no ar, inclusive com WAL);
 *   - files/public e files/private: o que foi enviado (fotos, arquivo morto da
 *     migracao, relatorios), sem a propria pasta de copias;
 *   - manifest.json: SHA-256 de cada parte, contagem de linhas por tabela e a
 *     ultima migration, para conferir a restauracao.
 *
 * O .env (APP_KEY, chaves do Stripe e do e-mail) NUNCA entra: segredo nao vai
 * para copia que pode sair do servidor. Com BACKUP_PASSWORD, o arquivo inteiro
 * e cifrado (AES-256).
 */
class BackupManager
{
    public const PREFIX = 'backup-';

    public const LAST_CACHE_KEY = 'backup:last';

    private const EXCLUDED_DIRS = ['backups', 'e2e', 'restauracao'];

    public function __construct(private readonly IntegrityChecker $integrity) {}

    public function directory(): string
    {
        return rtrim((string) config('barbearia.backup.path'), '/\\');
    }

    /**
     * Gera, confere e guarda uma copia nova; apaga as mais antigas so depois
     * que a nova passou na conferencia.
     *
     * @return array{path: string, size: int, sha256: string, tables: array<string, int>, files: int, encrypted: bool, removed: list<string>}
     */
    public function create(string $label = ''): array
    {
        $dir = $this->directory();
        File::ensureDirectoryExists($dir, 0700);
        $agora = Carbon::now('UTC');
        $nome = self::PREFIX.$agora->format('Ymd-His').($label !== '' ? '-'.Str::slug($label) : '').'.zip';
        $destino = $dir.DIRECTORY_SEPARATOR.$nome;
        $parcial = $dir.DIRECTORY_SEPARATOR.'.'.$nome.'.parcial';
        $trabalho = $this->tempDir();
        $aprovada = false;

        try {
            $banco = $trabalho.DIRECTORY_SEPARATOR.'database.sqlite';
            $this->snapshotDatabase($banco);
            $tabelas = $this->tableCounts($banco);
            $arquivos = $this->collectFiles();

            $manifesto = [
                'format' => 1,
                'created_at' => $agora->toIso8601String(),
                'app' => (string) config('app.name'),
                'environment' => app()->environment(),
                'database' => [
                    'driver' => 'sqlite',
                    'sha256' => hash_file('sha256', $banco),
                    'size' => filesize($banco),
                    'tables' => $tabelas,
                    'last_migration' => $this->lastMigration($banco),
                ],
                'files' => array_map(fn (array $f) => ['path' => $f['entry'], 'sha256' => $f['sha256'], 'size' => $f['size']], $arquivos),
                'media_disk' => (string) config('barbearia.media_disk'),
                'encrypted' => $this->password() !== null,
            ];

            $zip = new ZipArchive;
            if ($zip->open($parcial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo da cópia em '.$dir.'.');
            }
            $entradas = ['manifest.json', 'database.sqlite'];
            $zip->addFromString('manifest.json', (string) json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip->addFile($banco, 'database.sqlite');
            foreach ($arquivos as $f) {
                $zip->addFile($f['source'], $f['entry']);
                $entradas[] = $f['entry'];
            }
            $senha = $this->password();
            if ($senha !== null) {
                $zip->setPassword($senha);
                foreach ($entradas as $entrada) {
                    $zip->setEncryptionName($entrada, ZipArchive::EM_AES_256);
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Falha ao gravar o arquivo da cópia.');
            }
            rename($parcial, $destino);
            @chmod($destino, 0600);
            $hash = hash_file('sha256', $destino);
            file_put_contents($destino.'.sha256', $hash.'  '.$nome.PHP_EOL);

            $conferencia = $this->verify($destino);
            if (! $conferencia['ok']) {
                throw new RuntimeException('A cópia nova não passou na conferência: '.implode('; ', $conferencia['errors']));
            }
            $aprovada = true;

            $removidas = $this->rotate();
            $tamanho = (int) filesize($destino);
            Cache::forever(self::LAST_CACHE_KEY, ['at' => $agora->toIso8601String(), 'file' => $nome, 'size' => $tamanho]);
            Log::info('backup.created', ['arquivo' => $nome, 'bytes' => $tamanho, 'cifrado' => $senha !== null, 'removidas' => count($removidas)]);

            return [
                'path' => $destino, 'size' => $tamanho, 'sha256' => $hash, 'tables' => $tabelas,
                'files' => count($arquivos), 'encrypted' => $senha !== null, 'removed' => $removidas,
            ];
        } catch (Throwable $e) {
            @unlink($parcial);
            // Copia reprovada nao fica na pasta: o diagnostico nao pode conta-la
            // como a mais recente.
            if (! $aprovada && is_file($destino)) {
                @unlink($destino);
                @unlink($destino.'.sha256');
            }
            Log::error('backup.failed', ['erro' => $e->getMessage()]);
            throw $e;
        } finally {
            File::deleteDirectory($trabalho);
        }
    }

    /**
     * Confere uma copia sem tocar em nada da instalacao: hash do arquivo,
     * manifesto, hash e integridade do banco, contagem por tabela e hash de
     * cada arquivo.
     *
     * @return array{ok: bool, errors: list<string>, manifest: array<string, mixed>|null}
     */
    public function verify(string $path, ?string $password = null): array
    {
        $erros = [];
        if (! is_file($path)) {
            return ['ok' => false, 'errors' => ['Arquivo não encontrado: '.$path], 'manifest' => null];
        }
        if (is_file($path.'.sha256')) {
            $esperado = strtok((string) file_get_contents($path.'.sha256'), " \t\r\n");
            if ($esperado !== hash_file('sha256', $path)) {
                $erros[] = 'O SHA-256 do arquivo não confere com o .sha256 gravado junto (arquivo alterado ou corrompido).';
            }
        }

        $zip = $this->openZip($path, $password ?? $this->password());
        $bruto = $zip->getFromName('manifest.json');
        $manifesto = is_string($bruto) ? json_decode($bruto, true) : null;
        if (! is_array($manifesto)) {
            $zip->close();
            $erros[] = 'Manifesto ilegível (arquivo corrompido ou senha da cópia errada).';

            return ['ok' => false, 'errors' => $erros, 'manifest' => null];
        }

        $trabalho = $this->tempDir();
        try {
            $banco = $trabalho.DIRECTORY_SEPARATOR.'database.sqlite';
            if (! $this->extractEntry($zip, 'database.sqlite', $banco)) {
                $erros[] = 'O banco não pôde ser extraído.';
            } else {
                if (hash_file('sha256', $banco) !== ($manifesto['database']['sha256'] ?? null)) {
                    $erros[] = 'O SHA-256 do banco não confere com o manifesto.';
                }
                $check = $this->integrityCheck($banco);
                if ($check !== 'ok') {
                    $erros[] = 'PRAGMA integrity_check do banco: '.$check;
                }
                $contagem = $this->tableCounts($banco);
                foreach ((array) ($manifesto['database']['tables'] ?? []) as $tabela => $n) {
                    if (($contagem[$tabela] ?? -1) !== (int) $n) {
                        $erros[] = "Tabela {$tabela}: {$n} linhas no manifesto, ".($contagem[$tabela] ?? 'nenhuma').' na cópia.';
                    }
                }
            }
            foreach ((array) ($manifesto['files'] ?? []) as $f) {
                $stream = $zip->getStream((string) $f['path']);
                if ($stream === false) {
                    $erros[] = 'Arquivo ausente na cópia: '.$f['path'];

                    continue;
                }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                if (hash_final($ctx) !== $f['sha256']) {
                    $erros[] = 'Arquivo alterado na cópia: '.$f['path'];
                }
            }
        } finally {
            $zip->close();
            File::deleteDirectory($trabalho);
        }

        return ['ok' => $erros === [], 'errors' => $erros, 'manifest' => $manifesto];
    }

    /**
     * Extrai uma copia conferida para uma pasta NOVA (nunca por cima da
     * instalacao): <destino>/database.sqlite e <destino>/storage/app/...
     *
     * @return array{database: string, storage: string, manifest: array<string, mixed>}
     */
    public function extractTo(string $path, string $target, ?string $password = null): array
    {
        $conferencia = $this->verify($path, $password);
        if (! $conferencia['ok'] || $conferencia['manifest'] === null) {
            throw new RuntimeException('Cópia reprovada na conferência: '.implode('; ', $conferencia['errors']));
        }
        if (is_dir($target) && (new \FilesystemIterator($target))->valid()) {
            throw new RuntimeException('A pasta de destino já existe e não está vazia: '.$target);
        }
        File::ensureDirectoryExists($target, 0700);
        $zip = $this->openZip($path, $password ?? $this->password());
        try {
            $this->extractEntry($zip, 'database.sqlite', $target.DIRECTORY_SEPARATOR.'database.sqlite');
            foreach ((array) $conferencia['manifest']['files'] as $f) {
                $relativo = substr((string) $f['path'], strlen('files/'));
                $this->extractEntry($zip, (string) $f['path'], $target.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativo));
            }
        } finally {
            $zip->close();
        }

        return [
            'database' => $target.DIRECTORY_SEPARATOR.'database.sqlite',
            'storage' => $target.DIRECTORY_SEPARATOR.'storage',
            'manifest' => $conferencia['manifest'],
        ];
    }

    /**
     * Restaura uma copia NO LUGAR da instalacao atual (plano de retorno).
     * Ordem: confere a copia; tira uma copia do estado atual; entra em
     * manutencao; guarda banco e arquivos atuais numa pasta de restauracao;
     * coloca os da copia; aplica migrations pendentes; confere a integridade;
     * sai da manutencao so se tudo passou.
     *
     * @param  callable(string): void|null  $progress
     * @return array{safety_backup: string, set_aside: string, integrity: array<string, int>, online: bool}
     */
    public function replaceCurrent(string $path, ?string $password = null, ?callable $progress = null): array
    {
        $passo = $progress ?? fn (string $m) => null;
        $banco = $this->databasePath();

        $passo('Conferindo a cópia escolhida');
        $trabalho = $this->tempDir();
        try {
            $extraido = $this->extractTo($path, $trabalho.DIRECTORY_SEPARATOR.'copia', $password);

            $passo('Copiando o estado atual antes de restaurar');
            $seguranca = $this->create('antes-da-restauracao');

            $passo('Entrando em manutenção');
            Artisan::call('down', ['--retry' => 60]);

            $guardado = $this->directory().DIRECTORY_SEPARATOR.'restauracao-'.Carbon::now('UTC')->format('Ymd-His');
            File::ensureDirectoryExists($guardado, 0700);

            $passo('Guardando o banco e os arquivos atuais em '.$guardado);
            DB::disconnect();
            foreach (['', '-wal', '-shm'] as $sufixo) {
                if (is_file($banco.$sufixo)) {
                    rename($banco.$sufixo, $guardado.DIRECTORY_SEPARATOR.'database.sqlite'.$sufixo);
                }
            }
            foreach (['public', 'private'] as $raiz) {
                $atual = storage_path('app'.DIRECTORY_SEPARATOR.$raiz);
                $origem = $extraido['storage'].DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.$raiz;
                File::ensureDirectoryExists($guardado.DIRECTORY_SEPARATOR.$raiz);
                foreach ($this->topLevelEntries($atual) as $item) {
                    rename($atual.DIRECTORY_SEPARATOR.$item, $guardado.DIRECTORY_SEPARATOR.$raiz.DIRECTORY_SEPARATOR.$item);
                }
                if (is_dir($origem)) {
                    File::copyDirectory($origem, $atual);
                }
            }

            $passo('Colocando o banco da cópia');
            copy($extraido['database'], $banco);
            DB::reconnect();

            $passo('Aplicando migrations pendentes');
            Artisan::call('migrate', ['--force' => true]);

            $passo('Conferindo a integridade');
            $violacoes = $this->integrity->violations();
            $online = $violacoes === [];
            if ($online) {
                Artisan::call('up');
            }
            Log::warning('backup.restored', ['arquivo' => basename($path), 'integridade_ok' => $online]);

            return ['safety_backup' => $seguranca['path'], 'set_aside' => $guardado, 'integrity' => $violacoes, 'online' => $online];
        } finally {
            File::deleteDirectory($trabalho);
        }
    }

    /**
     * Copias existentes, da mais nova para a mais antiga.
     *
     * @return list<string>
     */
    public function list(): array
    {
        $arquivos = glob($this->directory().DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];
        rsort($arquivos);

        return $arquivos;
    }

    /**
     * Idade da copia mais recente em horas (null = nenhuma).
     */
    public function latestAgeHours(): ?float
    {
        $ultima = $this->list()[0] ?? null;

        return $ultima === null ? null : (time() - (int) filemtime($ultima)) / 3600;
    }

    private function password(): ?string
    {
        $senha = (string) config('barbearia.backup.password');

        return $senha === '' ? null : $senha;
    }

    private function databasePath(): string
    {
        $conexao = (string) config('database.default');
        if (config("database.connections.{$conexao}.driver") !== 'sqlite') {
            throw new RuntimeException('A cópia automática cobre o banco SQLite (D-02). Para MySQL/MariaDB use o backup do servidor de banco (operacao.md).');
        }
        $caminho = (string) config("database.connections.{$conexao}.database");
        if ($caminho === '' || $caminho === ':memory:' || ! is_file($caminho)) {
            throw new RuntimeException('Banco SQLite não encontrado para a cópia.');
        }

        return $caminho;
    }

    private function snapshotDatabase(string $destino): void
    {
        $origem = new SQLite3($this->databasePath(), SQLITE3_OPEN_READWRITE);
        $origem->busyTimeout(15000);
        $copia = new SQLite3($destino);
        try {
            if (! $origem->backup($copia)) {
                throw new RuntimeException('A API de backup do SQLite recusou a cópia.');
            }
        } finally {
            $copia->close();
            $origem->close();
        }
        // A copia fica em modo de diario simples: um arquivo so, sem -wal.
        $c = new SQLite3($destino);
        $c->exec('PRAGMA journal_mode = DELETE');
        $c->close();
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(string $banco): array
    {
        $db = new SQLite3($banco, SQLITE3_OPEN_READONLY);
        $contagem = [];
        $tabelas = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        while ($tabelas !== false && ($t = $tabelas->fetchArray(SQLITE3_ASSOC))) {
            $contagem[(string) $t['name']] = (int) $db->querySingle('SELECT COUNT(*) FROM "'.str_replace('"', '""', (string) $t['name']).'"');
        }
        $db->close();

        return $contagem;
    }

    private function integrityCheck(string $banco): string
    {
        $db = new SQLite3($banco, SQLITE3_OPEN_READONLY);
        $r = (string) $db->querySingle('PRAGMA integrity_check');
        $db->close();

        return $r;
    }

    private function lastMigration(string $banco): ?string
    {
        $db = new SQLite3($banco, SQLITE3_OPEN_READONLY);
        $existe = $db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'migrations'");
        $r = $existe ? $db->querySingle('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1') : null;
        $db->close();

        return is_string($r) ? $r : null;
    }

    /**
     * @return list<array{source: string, entry: string, sha256: string, size: int}>
     */
    private function collectFiles(): array
    {
        $copias = realpath($this->directory()) ?: $this->directory();
        $lista = [];
        foreach (['public', 'private'] as $raiz) {
            $base = storage_path('app'.DIRECTORY_SEPARATOR.$raiz);
            if (! is_dir($base)) {
                continue;
            }
            foreach (File::allFiles($base, true) as $arquivo) {
                $relativo = str_replace('\\', '/', $arquivo->getRelativePathname());
                $primeiro = explode('/', $relativo)[0];
                $real = (string) $arquivo->getRealPath();
                if ($arquivo->getFilename() === '.gitignore' || in_array($primeiro, self::EXCLUDED_DIRS, true) || str_starts_with($real, $copias)) {
                    continue;
                }
                $lista[] = ['source' => $real, 'entry' => 'files/'.$raiz.'/'.$relativo, 'sha256' => (string) hash_file('sha256', $real), 'size' => (int) $arquivo->getSize()];
            }
        }

        return $lista;
    }

    /**
     * @return list<string>
     */
    private function rotate(): array
    {
        $manter = max(1, (int) config('barbearia.backup.keep', 14));
        $removidas = [];
        foreach (array_slice($this->list(), $manter) as $antiga) {
            @unlink($antiga);
            @unlink($antiga.'.sha256');
            $removidas[] = basename($antiga);
        }

        return $removidas;
    }

    private function openZip(string $path, ?string $password): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arquivo de cópia ilegível: '.basename($path));
        }
        if ($password !== null) {
            $zip->setPassword($password);
        }

        return $zip;
    }

    private function extractEntry(ZipArchive $zip, string $entry, string $destino): bool
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return false;
        }
        File::ensureDirectoryExists(dirname($destino), 0700);
        $saida = fopen($destino, 'wb');
        if ($saida === false) {
            fclose($stream);

            return false;
        }
        $bytes = stream_copy_to_stream($stream, $saida);
        fclose($stream);
        fclose($saida);

        return $bytes !== false;
    }

    /**
     * @return list<string>
     */
    private function topLevelEntries(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(scandir($dir) ?: [], fn (string $i) => ! in_array($i, ['.', '..', '.gitignore'], true)
            && ! in_array($i, self::EXCLUDED_DIRS, true)));
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework'.DIRECTORY_SEPARATOR.'backup-'.Str::random(12));
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }
}

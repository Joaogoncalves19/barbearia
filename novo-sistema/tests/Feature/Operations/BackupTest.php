<?php

namespace Tests\Feature\Operations;

use App\Modules\Customers\Models\Customer;
use App\Modules\System\Backup\BackupManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use ZipArchive;

/**
 * Copia de seguranca (Fase 13): banco consistente + arquivos + manifesto,
 * conferida ao gravar, cifrada com senha, rotacao, restauracao numa pasta
 * nova e no lugar da instalacao, diagnostico e aviso de monitoramento.
 * Banco em ARQUIVO (a copia usa a API de backup do SQLite) e pasta de
 * storage temporaria: nada da instalacao de desenvolvimento e tocado.
 */
class BackupTest extends TestCase
{
    private string $dir;

    private string $db;

    private string $storageOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'copia-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/storage/app/public/professionals');
        File::ensureDirectoryExists($this->dir.'/storage/app/private/legacy-import');
        File::ensureDirectoryExists($this->dir.'/storage/framework');
        file_put_contents($this->dir.'/storage/app/public/professionals/foto-ficticia.webp', 'imagem-ficticia');
        file_put_contents($this->dir.'/storage/app/private/legacy-import/relatorio.json', '{"ficticio":true}');
        $this->storageOriginal = $this->app->storagePath();
        $this->app->useStoragePath($this->dir.'/storage');

        $this->db = $this->dir.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($this->db);
        config([
            'database.connections.sqlite.database' => $this->db,
            'barbearia.backup.path' => $this->dir.DIRECTORY_SEPARATOR.'copias',
            'barbearia.backup.password' => null,
            'barbearia.backup.keep' => 14,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Customer::factory()->count(3)->create();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        $this->app->useStoragePath($this->storageOriginal);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function backups(): BackupManager
    {
        return app(BackupManager::class);
    }

    public function test_copia_tem_banco_arquivos_e_manifesto_e_e_conferida(): void
    {
        $this->artisan('app:backup')->expectsOutputToContain('Cópia gerada e conferida')->assertSuccessful();

        $copias = $this->backups()->list();
        $this->assertCount(1, $copias);
        $this->assertFileExists($copias[0].'.sha256');

        $zip = new ZipArchive;
        $zip->open($copias[0]);
        $nomes = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $manifesto = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertContains('database.sqlite', $nomes);
        $this->assertContains('files/public/professionals/foto-ficticia.webp', $nomes);
        $this->assertContains('files/private/legacy-import/relatorio.json', $nomes);
        $this->assertSame(3, $manifesto['database']['tables']['customers']);
        $this->assertNotNull($manifesto['database']['last_migration']);
        $this->assertEmpty(array_filter($nomes, fn ($n) => str_contains((string) $n, '.env') || str_contains((string) $n, 'copias/')));
        $this->assertStringNotContainsString((string) config('app.key'), (string) file_get_contents($copias[0]), 'APP_KEY nunca entra na cópia');

        $this->artisan('app:backup-verify')->expectsOutputToContain('Aprovada')->assertSuccessful();
    }

    public function test_copia_cifrada_so_abre_com_a_senha(): void
    {
        config(['barbearia.backup.password' => 'senha-ficticia-de-teste']);
        $r = $this->backups()->create();
        $this->assertTrue($r['encrypted']);

        $zip = new ZipArchive;
        $zip->open($r['path']);
        $this->assertFalse($zip->getFromName('manifest.json'), 'Sem senha não lê nada');
        $zip->close();

        $this->assertFalse($this->backups()->verify($r['path'], 'senha-errada')['ok']);
        $this->assertTrue($this->backups()->verify($r['path'])['ok']);
    }

    public function test_conferencia_reprova_copia_alterada(): void
    {
        $r = $this->backups()->create();
        $h = fopen($r['path'], 'r+b');
        fseek($h, 40);
        fwrite($h, 'XXXX');
        fclose($h);

        $this->artisan('app:backup-verify', ['file' => $r['path']])->expectsOutputToContain('REPROVADA')->assertFailed();
    }

    public function test_rotacao_mantem_so_as_mais_recentes(): void
    {
        config(['barbearia.backup.keep' => 2]);
        foreach (['2026-10-01 05:00', '2026-10-02 05:00', '2026-10-03 05:00'] as $quando) {
            Carbon::setTestNow(Carbon::parse($quando, 'UTC'));
            $this->backups()->create();
        }
        Carbon::setTestNow();

        $restantes = array_map('basename', $this->backups()->list());
        $this->assertSame(['backup-20261003-050000.zip', 'backup-20261002-050000.zip'], $restantes);
        $this->assertFileDoesNotExist($this->dir.'/copias/backup-20261001-050000.zip.sha256');
    }

    public function test_restauracao_em_pasta_nova_reproduz_banco_e_arquivos(): void
    {
        $r = $this->backups()->create();
        $destino = $this->dir.DIRECTORY_SEPARATOR.'ensaio-restauracao';

        $this->artisan('app:backup-restore', ['file' => $r['path'], '--to' => $destino])->assertSuccessful();

        $restaurado = new \PDO('sqlite:'.$destino.DIRECTORY_SEPARATOR.'database.sqlite');
        $this->assertSame(3, (int) $restaurado->query('select count(*) from customers')->fetchColumn());
        $this->assertSame('imagem-ficticia', file_get_contents($destino.'/storage/app/public/professionals/foto-ficticia.webp'));

        $this->artisan('app:backup-restore', ['file' => $r['path'], '--to' => $destino])
            ->expectsOutputToContain('não está vazia')->assertFailed();
    }

    public function test_restauracao_no_lugar_volta_ao_estado_da_copia_e_guarda_o_atual(): void
    {
        $copia = $this->backups()->create();
        Customer::factory()->count(2)->create();
        unlink($this->dir.'/storage/app/public/professionals/foto-ficticia.webp');
        file_put_contents($this->dir.'/storage/app/public/professionals/foto-nova.webp', 'depois-da-copia');

        $this->artisan('app:backup-restore', ['file' => $copia['path'], '--replace-current' => true])
            ->expectsConfirmation('Substituir o banco e os arquivos ATUAIS pela cópia '.basename($copia['path']).'? (o estado atual é copiado antes)', 'no')
            ->assertFailed();
        $this->assertSame(5, Customer::count(), 'Sem confirmação nada muda');

        $this->artisan('app:backup-restore', ['file' => $copia['path'], '--replace-current' => true, '--force' => true])
            ->expectsOutputToContain('de volta ao ar')
            ->assertSuccessful();

        $this->assertSame(3, Customer::count());
        $this->assertFileExists($this->dir.'/storage/app/public/professionals/foto-ficticia.webp');
        $this->assertFileDoesNotExist($this->dir.'/storage/app/public/professionals/foto-nova.webp');
        $this->assertFalse($this->app->isDownForMaintenance());

        $seguranca = array_values(array_filter($this->backups()->list(), fn ($f) => str_contains($f, 'antes-da-restauracao')));
        $this->assertCount(1, $seguranca, 'O estado anterior virou uma cópia');
        $this->assertSame(5, $this->backups()->verify($seguranca[0])['manifest']['database']['tables']['customers']);
        $guardado = glob($this->dir.'/copias/restauracao-*') ?: [];
        $this->assertFileExists($guardado[0].'/database.sqlite');
        $this->assertFileExists($guardado[0].'/public/professionals/foto-nova.webp');
    }

    public function test_diagnostico_avisa_sem_copia_e_monitoramento_manda_um_aviso_so(): void
    {
        config(['barbearia.monitor.email' => 'monitor@exemplo.test', 'mail.default' => 'array']);
        Cache::flush();

        $this->artisan('app:diagnose')->expectsOutputToContain('nenhuma cópia')->assertSuccessful();

        $this->artisan('app:diagnose', ['--alert' => true])->assertSuccessful();
        $this->artisan('app:diagnose', ['--alert' => true])->assertSuccessful();
        $enviados = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $enviados, 'O mesmo problema avisa uma vez a cada 6 h');
        $this->assertStringContainsString('Cópia de segurança recente', (string) $enviados[0]->getOriginalMessage()->getTextBody());

        $this->backups()->create();
        $this->artisan('app:diagnose')->expectsOutputToContain('última há')->assertSuccessful();
    }

    public function test_agendador_faz_copia_diaria_e_monitoramento_de_hora_em_hora(): void
    {
        $eventos = collect(app(Schedule::class)->events());
        $copia = $eventos->first(fn ($e) => str_contains((string) $e->command, 'app:backup'));
        $this->assertNotNull($copia);
        $this->assertSame('40 2 * * *', $copia->expression);
        $this->assertSame('America/Sao_Paulo', $copia->timezone);
        $this->assertNotNull($eventos->first(fn ($e) => str_contains((string) $e->command, 'app:diagnose --alert')));
    }

    public function test_saude_responde_erro_quando_o_banco_cai(): void
    {
        $this->get('/up')->assertOk();

        config(['database.connections.sqlite.database' => $this->dir.DIRECTORY_SEPARATOR.'nao-existe'.DIRECTORY_SEPARATOR.'x.sqlite']);
        DB::purge('sqlite');
        $this->get('/up')->assertStatus(500);
    }
}

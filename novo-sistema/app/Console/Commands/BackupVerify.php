<?php

namespace App\Console\Commands;

use App\Modules\System\Backup\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Confere uma copia sem tocar na instalacao (hash, manifesto, integridade do
 * banco, contagem por tabela e cada arquivo). Sem argumento: a mais recente.
 *
 *     php artisan app:backup-verify [arquivo.zip]
 */
#[Signature('app:backup-verify {file? : Arquivo da cópia (padrão: a mais recente)}')]
#[Description('Confere uma cópia de segurança sem restaurar nada')]
class BackupVerify extends Command
{
    public function handle(BackupManager $backups): int
    {
        $arquivo = (string) ($this->argument('file') ?: ($backups->list()[0] ?? ''));
        if ($arquivo === '') {
            $this->error('Nenhuma cópia encontrada em '.$backups->directory().'.');

            return self::FAILURE;
        }

        try {
            $r = $backups->verify($arquivo);
        } catch (Throwable $e) {
            $this->error('Conferência falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $r['ok']) {
            $this->error('REPROVADA: '.$arquivo);
            foreach ($r['errors'] as $erro) {
                $this->line('  - '.$erro);
            }

            return self::FAILURE;
        }

        $m = (array) $r['manifest'];
        $this->info('Aprovada: '.$arquivo);
        $this->line(sprintf('  gerada em %s · %d tabelas · %d arquivos · última migration %s',
            $m['created_at'] ?? '?', count((array) ($m['database']['tables'] ?? [])), count((array) ($m['files'] ?? [])),
            $m['database']['last_migration'] ?? '?'));

        return self::SUCCESS;
    }
}

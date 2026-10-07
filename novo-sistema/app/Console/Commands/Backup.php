<?php

namespace App\Console\Commands;

use App\Modules\System\Backup\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copia de seguranca da instalacao (banco + arquivos enviados), conferida
 * logo depois de gravada. Roda todo dia pelo agendador (routes/console.php)
 * e pode ser chamada a mao antes de qualquer operacao arriscada:
 *
 *     php artisan app:backup
 */
#[Signature('app:backup {--label= : Sufixo no nome do arquivo (ex.: antes-da-virada)}')]
#[Description('Gera e confere uma cópia de segurança do banco e dos arquivos')]
class Backup extends Command
{
    public function handle(BackupManager $backups): int
    {
        try {
            $r = $backups->create((string) $this->option('label'));
        } catch (Throwable $e) {
            $this->error('Cópia NÃO gerada: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Cópia gerada e conferida: '.$r['path']);
        $this->line(sprintf('  %s · %d tabelas · %d arquivos · %s · SHA-256 %s',
            number_format($r['size'] / 1024, 0, ',', '.').' KB', count($r['tables']), $r['files'],
            $r['encrypted'] ? 'cifrada (AES-256)' : 'SEM cifra (defina BACKUP_PASSWORD antes de tirar do servidor)',
            $r['sha256']));
        foreach ($r['removed'] as $antiga) {
            $this->line('  removida (mais antiga que as '.config('barbearia.backup.keep').' mantidas): '.$antiga);
        }

        return self::SUCCESS;
    }
}

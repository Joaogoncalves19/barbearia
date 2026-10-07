<?php

namespace App\Console\Commands;

use App\Modules\System\Backup\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restaura uma copia conferida.
 *
 *   --to=<pasta>         extrai numa pasta NOVA (ensaio de restauracao; nada
 *                        da instalacao e tocado)
 *   --replace-current    coloca a copia NO LUGAR da instalacao (plano de
 *                        retorno). Antes tira uma copia do estado atual e
 *                        guarda banco e arquivos atuais; exige --force fora do
 *                        modo interativo.
 */
#[Signature('app:backup-restore {file : Arquivo da cópia} {--to= : Pasta nova onde extrair} {--replace-current : Substituir a instalação atual} {--force : Confirmar a substituição sem perguntar}')]
#[Description('Restaura uma cópia de segurança (numa pasta nova ou no lugar da atual)')]
class BackupRestore extends Command
{
    public function handle(BackupManager $backups): int
    {
        $arquivo = (string) $this->argument('file');
        $pasta = (string) $this->option('to');
        $substituir = (bool) $this->option('replace-current');

        if (($pasta === '') === ! $substituir) {
            $this->error('Escolha um destino: --to=<pasta nova> OU --replace-current.');

            return self::FAILURE;
        }

        try {
            if ($pasta !== '') {
                $r = $backups->extractTo($arquivo, $pasta);
                $this->info('Cópia conferida e extraída em '.$pasta);
                $this->line('  banco: '.$r['database']);
                $this->line('  arquivos: '.$r['storage']);

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! ($this->input->isInteractive()
                && $this->confirm('Substituir o banco e os arquivos ATUAIS pela cópia '.basename($arquivo).'? (o estado atual é copiado antes)'))) {
                $this->error('Restauração cancelada. Use --force para confirmar.');

                return self::FAILURE;
            }

            $r = $backups->replaceCurrent($arquivo, progress: fn (string $m) => $this->line('  · '.$m));
        } catch (Throwable $e) {
            $this->error('Restauração interrompida: '.$e->getMessage());
            $this->line('Se o sistema ficou em manutenção, confira e rode `php artisan up` só depois de resolver.');

            return self::FAILURE;
        }

        $this->line('Cópia do estado anterior: '.$r['safety_backup']);
        $this->line('Banco e arquivos anteriores guardados em: '.$r['set_aside']);
        if (! $r['online']) {
            $this->error('Integridade com problemas ('.json_encode($r['integrity']).'). O sistema CONTINUA EM MANUTENÇÃO.');

            return self::FAILURE;
        }
        $this->info('Restaurada, íntegra e de volta ao ar.');

        return self::SUCCESS;
    }
}

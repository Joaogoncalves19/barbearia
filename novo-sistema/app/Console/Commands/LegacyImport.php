<?php

namespace App\Console\Commands;

use App\Modules\LegacyImport\LegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * php artisan legacy:import caminho/para/database.sqlite [--dry-run]
 *
 * Le uma COPIA do banco do sistema antigo (somente leitura) e importa para
 * o banco novo. Idempotente: pode rodar de novo sem duplicar.
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import
        {source : Caminho da COPIA do banco SQLite do sistema antigo}
        {--dry-run : Simula tudo e desfaz; grava so o relatorio}
        {--force : Permite rodar em producao}';

    protected $description = 'Importa os dados do sistema antigo (com simulacao, conciliacao e relatorio de pendencias)';

    public function handle(LegacyImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');

        if (App::isProduction() && ! $dry && ! $this->option('force')) {
            $this->error('Em producao a importacao real exige --force (rode antes com --dry-run).');

            return self::FAILURE;
        }

        $this->info(($dry ? 'SIMULACAO' : 'IMPORTACAO').' a partir de '.$this->argument('source'));
        $r = $importer->run((string) $this->argument('source'), $dry, progress: fn (string $etapa) => $this->line("  · {$etapa}"));

        $this->newLine();
        $this->table(['Tabela', 'Lidos', 'Importados', 'Ja importados', 'Nao importados', 'Pendencias'], collect($r['counters'])
            ->sortKeys()->map(fn ($c, $t) => [$t, $c['read'] ?? 0, $c['imported'] ?? 0, $c['unchanged'] ?? 0, $c['skipped'] ?? 0, $c['issues'] ?? 0])->values()->all());

        foreach ($r['reconciliation'] ?? [] as $nome => $v) {
            $this->line(($v['ok'] ? '<info>OK</info>   ' : '<error>FALHA</error> ').$nome);
        }
        $this->line('Integridade: '.(($r['integrity'] ?? []) === [] ? '<info>OK</info>' : '<error>'.json_encode($r['integrity']).'</error>'));
        $this->line('Origem inalterada: '.($r['source_unchanged'] ? '<info>sim</info>' : '<error>NAO</error>'));
        $this->line('Relatorios em storage/app/private/: '.implode(', ', $r['reports']));

        if ($r['status'] === 'failed') {
            $this->error('Falhou: '.($r['error'] ?? 'erro desconhecido').' Nada foi gravado.');

            return self::FAILURE;
        }
        $this->info($dry ? 'Simulacao concluida: nenhum dado foi gravado.' : 'Importacao concluida.');

        return self::SUCCESS;
    }
}

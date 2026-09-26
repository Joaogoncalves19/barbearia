<?php

namespace App\Console\Commands;

use App\Modules\LegacyImport\Testing\FictitiousLegacyDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Gera um banco do sistema antigo com dados FICTICIOS para validar o
 * importador (e medir desempenho com volume). Nunca em producao.
 */
class LegacyFixture extends Command
{
    protected $signature = 'legacy:fixture
        {path : Arquivo SQLite a gerar (sobrescreve)}
        {--customers=60 : Quantidade de clientes normais}
        {--appointments=300 : Quantidade de agendamentos normais}
        {--minimal-schema : Simula instalacao antiga, sem colunas acrescentadas depois}';

    protected $description = 'Gera um banco do sistema antigo com dados ficticios (normais e problematicos) para testar o importador';

    public function handle(): int
    {
        if (App::isProduction()) {
            $this->error('Nao disponivel em producao.');

            return self::FAILURE;
        }

        $path = (new FictitiousLegacyDatabase(
            (string) $this->argument('path'),
            (int) $this->option('customers'),
            (int) $this->option('appointments'),
            (bool) $this->option('minimal-schema'),
        ))->build();

        $this->info("Banco ficticio gerado: {$path}");

        return self::SUCCESS;
    }
}

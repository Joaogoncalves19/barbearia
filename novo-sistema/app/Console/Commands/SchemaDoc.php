<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Gera em Markdown o esquema fisico do banco (tabelas, colunas, indices,
 * FKs) a partir do banco migrado, para o apendice de modelo-dados.md.
 */
class SchemaDoc extends Command
{
    protected $signature = 'app:schema-doc';

    protected $description = 'Imprime o esquema fisico do banco em Markdown (apendice de modelo-dados.md)';

    private const TECNICAS = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens', 'customer_password_reset_tokens'];

    public function handle(): int
    {
        $tabelas = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::TECNICAS, true) || str_starts_with($t, 'sqlite_'))->sort()->values();

        $md = "Gerado por `php artisan app:schema-doc` ({$tabelas->count()} tabelas de dominio; tabelas tecnicas do Laravel omitidas).\n";
        foreach ($tabelas as $t) {
            $fks = collect(Schema::getForeignKeys($t))->keyBy(fn ($f) => implode(',', $f['columns']));
            $unicos = collect(Schema::getIndexes($t))->filter(fn ($i) => $i['unique'] && ! $i['primary'])->map(fn ($i) => implode(', ', $i['columns']));
            $indices = collect(Schema::getIndexes($t))->reject(fn ($i) => $i['unique'] || $i['primary'])->map(fn ($i) => implode(', ', $i['columns']));

            $md .= "\n### `{$t}`\n\n| Coluna | Tipo | Nulo | Padrao | FK |\n|---|---|---|---|---|\n";
            foreach (Schema::getColumns($t) as $c) {
                $fk = $fks->get($c['name']);
                $md .= "| `{$c['name']}` | {$c['type_name']} | ".($c['nullable'] ? 'sim' : 'nao').' | '.($c['default'] === null ? '' : '`'.trim((string) $c['default'], "'").'`').' | '
                    .($fk ? "{$fk['foreign_table']}.".implode(',', $fk['foreign_columns']).' ('.strtolower($fk['on_delete']).')' : '')." |\n";
            }
            if ($unicos->isNotEmpty()) {
                $md .= "\nUnicos: ".$unicos->map(fn ($u) => "({$u})")->implode(' · ')."\n";
            }
            if ($indices->isNotEmpty()) {
                $md .= "\nIndices: ".$indices->map(fn ($u) => "({$u})")->implode(' · ')."\n";
            }
        }
        $this->line($md);

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\LegacyImport\Support;

use App\Modules\LegacyImport\Enums\IssueClassification;
use App\Modules\LegacyImport\Enums\IssueSeverity;
use App\Modules\LegacyImport\Source\LegacyDatabase;
use App\Modules\Shared\Support\UniqueSlug;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estado de uma execucao do importador: origem, mapa id antigo -> id novo,
 * contadores e pendencias.
 *
 * Idempotencia: cada linha de origem tem uma chave (source_table, source_id)
 * em legacy_references. Na segunda execucao a linha ja mapeada e pulada
 * (unchanged) ou, se o conteudo mudou na origem, pulada com pendencia
 * (changed): atualizar dado ja importado e decisao humana.
 */
final class ImportContext
{
    /** @var array<string, array<string, int>> */
    public array $counters = [];

    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var array<string, array<string, int>> */
    private array $maps = [];

    /** @var array<string, array<string, string>> */
    private array $checksums = [];

    /** @var array<string, array<string, int>> */
    private array $occurrences = [];

    public readonly CarbonImmutable $now;

    public function __construct(
        public readonly LegacyDatabase $source,
        public readonly bool $dryRun,
        public readonly ?int $runId,
        public readonly string $timezone,
        ?CarbonImmutable $now = null,
    ) {
        $this->now = $now ?? CarbonImmutable::now('UTC');
    }

    public function count(string $table, string $counter, int $by = 1): void
    {
        $this->counters[$table][$counter] = ($this->counters[$table][$counter] ?? 0) + $by;
    }

    /**
     * Situacao da linha de origem nesta execucao.
     *
     * @param  array<string, mixed>  $row
     * @return 'new'|'unchanged'|'changed'
     */
    public function status(string $table, string $sourceId, array $row): string
    {
        $this->count($table, 'read');
        $this->loadMap($table);

        if (! isset($this->maps[$table][$sourceId])) {
            return 'new';
        }
        if (($this->checksums[$table][$sourceId] ?? null) === self::checksum($row)) {
            $this->count($table, 'unchanged');

            return 'unchanged';
        }

        $this->count($table, 'changed');
        $this->issue($table, $sourceId, IssueClassification::PotentiallyValid, IssueSeverity::Warning, 'source_changed',
            'Registro ja importado mudou na origem desde a ultima execucao; nao foi atualizado.', [], true);

        return 'changed';
    }

    /** Id novo do registro antigo, se ja importado. */
    public function ref(string $table, ?string $sourceId): ?int
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }
        $this->loadMap($table);

        return $this->maps[$table][$sourceId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function remember(string $table, string $sourceId, string $entityType, int $entityId, array $row): void
    {
        $checksum = self::checksum($row);
        DB::table('legacy_references')->insert([
            'source_table' => $table,
            'source_id' => $sourceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'checksum' => $checksum,
            'import_run_id' => $this->runId,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        $this->maps[$table][$sourceId] = $entityId;
        $this->checksums[$table][$sourceId] = $checksum;
        $this->count($table, 'imported');
    }

    /**
     * Chave estavel para tabelas antigas sem chave primaria: hash do
     * conteudo + numero da ocorrencia (linhas identicas repetidas nao colidem).
     *
     * @param  array<string, mixed>  $row
     */
    public function contentKey(string $table, array $row): string
    {
        $hash = substr(self::checksum($row), 0, 32);
        $n = $this->occurrences[$table][$hash] = ($this->occurrences[$table][$hash] ?? 0) + 1;

        return $hash.'#'.$n;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function issue(string $table, ?string $sourceId, IssueClassification $classification, IssueSeverity $severity, string $code, string $message, array $context = [], bool $needsDecision = false): void
    {
        $this->issues[] = [
            'source_table' => $table,
            'source_id' => $sourceId,
            'classification' => $classification->value,
            'severity' => $severity->value,
            'code' => $code,
            'message' => $message,
            'context' => $context,
            'needs_decision' => $needsDecision,
        ];
        $this->count($table, 'issues');
    }

    /**
     * Linha NAO importada: conta como "skipped" e vira pendencia de erro.
     *
     * @param  array<string, mixed>  $context
     */
    public function skip(string $table, ?string $sourceId, IssueClassification $classification, string $code, string $message, array $context = [], bool $needsDecision = true): void
    {
        $this->count($table, 'skipped');
        $this->issue($table, $sourceId, $classification, IssueSeverity::Error, $code, $message, $context, $needsDecision);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(string $table, array $values): int
    {
        // Mesma regra de identificador estavel dos models (Fase 4).
        if (isset(self::SLUG_SOURCE[$table]) && ! isset($values['slug'])) {
            $values['slug'] = UniqueSlug::for($table, (string) ($values[self::SLUG_SOURCE[$table]] ?? ''));
        }

        return (int) DB::table($table)->insertGetId($values);
    }

    /** Tabelas com slug e a coluna de onde ele nasce. */
    private const SLUG_SOURCE = ['service_categories' => 'name', 'services' => 'name', 'professionals' => 'display_name'];

    /**
     * @param  array<string, mixed>  $row
     */
    public static function checksum(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function loadMap(string $table): void
    {
        if (isset($this->maps[$table])) {
            return;
        }
        $this->maps[$table] = [];
        $this->checksums[$table] = [];
        foreach (DB::table('legacy_references')->where('source_table', $table)->get(['source_id', 'entity_id', 'checksum']) as $r) {
            $this->maps[$table][$r->source_id] = (int) $r->entity_id;
            $this->checksums[$table][$r->source_id] = $r->checksum;
        }
    }
}

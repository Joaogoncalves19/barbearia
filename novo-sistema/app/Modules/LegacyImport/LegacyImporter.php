<?php

namespace App\Modules\LegacyImport;

use App\Modules\LegacyImport\Reporting\ReportWriter;
use App\Modules\LegacyImport\Source\LegacyDatabase;
use App\Modules\LegacyImport\Steps\AppointmentsStep;
use App\Modules\LegacyImport\Steps\ArchiveStep;
use App\Modules\LegacyImport\Steps\CatalogStep;
use App\Modules\LegacyImport\Steps\CustomersStep;
use App\Modules\LegacyImport\Steps\FinanceStep;
use App\Modules\LegacyImport\Steps\LoyaltyStep;
use App\Modules\LegacyImport\Steps\MarketingAuditStep;
use App\Modules\LegacyImport\Steps\ProfessionalsStep;
use App\Modules\LegacyImport\Steps\PromotionsStep;
use App\Modules\LegacyImport\Steps\ReviewsStep;
use App\Modules\LegacyImport\Steps\SettingsStep;
use App\Modules\LegacyImport\Steps\StaffStep;
use App\Modules\LegacyImport\Steps\Step;
use App\Modules\LegacyImport\Steps\StockStep;
use App\Modules\LegacyImport\Steps\SubscriptionsStep;
use App\Modules\LegacyImport\Support\ImportContext;
use App\Modules\LegacyImport\Support\Reconciler;
use App\Modules\System\Integrity\IntegrityChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Orquestra a importacao do banco antigo.
 *
 * - Tudo roda numa UNICA transacao: ou entra tudo, ou nada.
 * - dry-run: executa exatamente o mesmo caminho e desfaz no final; so o
 *   relatorio e gravado (em arquivo).
 * - Importacao real: falha (e desfaz) se a conciliacao ou a verificacao de
 *   integridade nao fecharem.
 * - A origem e aberta somente leitura e o hash do arquivo e conferido antes
 *   e depois (prova de que nao foi alterada).
 */
class LegacyImporter
{
    public function __construct(private readonly ReportWriter $reports, private readonly IntegrityChecker $integrity) {}

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        $steps = [
            new SettingsStep, new StaffStep, new CatalogStep, new ProfessionalsStep, new CustomersStep,
            new SubscriptionsStep, new PromotionsStep, new AppointmentsStep, new ReviewsStep,
            new LoyaltyStep, new StockStep, new FinanceStep, new MarketingAuditStep,
        ];
        $mapeadas = array_merge(...array_map(fn (Step $s) => $s->tables(), $steps));

        return [...$steps, new ArchiveStep([...$mapeadas, 'produtos', 'servicos', 'combos', 'vouchers'])];
    }

    /**
     * @return array<string, mixed> resultado (contadores, conciliacao, relatorios)
     */
    public function run(string $sourcePath, bool $dryRun, ?CarbonImmutable $now = null, ?callable $progress = null): array
    {
        $source = new LegacyDatabase($sourcePath);
        $hashAntes = $source->sha256();
        $now ??= CarbonImmutable::now('UTC');

        $runId = null;
        if (! $dryRun) {
            $runId = (int) DB::table('import_runs')->insertGetId([
                'mode' => 'import', 'status' => 'running', 'source_path' => $sourcePath, 'source_sha256' => $hashAntes,
                'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $ctx = new ImportContext($source, $dryRun, $runId, (string) config('barbearia.display_timezone', 'America/Sao_Paulo'), $now);
        $resultado = ['mode' => $dryRun ? 'dry_run' : 'import', 'run_id' => $runId, 'source' => $sourcePath, 'source_sha256' => $hashAntes, 'started_at' => $now->toIso8601String()];
        $inicio = microtime(true);

        try {
            DB::transaction(function () use ($ctx, $progress, &$resultado, $dryRun, $source) {
                foreach ($this->steps() as $step) {
                    $t = microtime(true);
                    $progress && $progress($step->name());
                    $step->run($ctx);
                    $resultado['timings'][$step->name()] = round(microtime(true) - $t, 3);
                }

                $resultado['reconciliation'] = (new Reconciler($source, $ctx->timezone))->run();
                $resultado['integrity'] = $this->integrity->violations();
                $falhas = array_keys(array_filter($resultado['reconciliation'], fn ($r) => ! $r['ok']));

                if ($ctx->runId !== null) {
                    $this->persistIssues($ctx);
                }

                if ($dryRun) {
                    throw new DryRunRollback;
                }
                if ($falhas !== [] || $resultado['integrity'] !== []) {
                    throw new RuntimeException('Conciliacao/integridade nao fechou: '.implode(', ', [...$falhas, ...array_keys($resultado['integrity'])]).'. Nada foi gravado.');
                }
            });
        } catch (DryRunRollback) {
            // esperado: simulacao desfeita
        } catch (Throwable $e) {
            $resultado['error'] = $e->getMessage();
        }

        $resultado['counters'] = $ctx->counters;
        $resultado['issues'] = $ctx->issues;
        $resultado['duration_seconds'] = round(microtime(true) - $inicio, 3);
        $resultado['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $resultado['source_sha256_after'] = $source->sha256();
        $resultado['source_unchanged'] = $resultado['source_sha256_after'] === $hashAntes;
        $resultado['status'] = isset($resultado['error']) ? 'failed' : ($dryRun ? 'rolled_back' : 'completed');
        if (! $resultado['source_unchanged']) {
            $resultado['status'] = 'failed';
            $resultado['error'] = ($resultado['error'] ?? '').' O arquivo de origem mudou durante a execucao.';
        }

        $resultado['reports'] = $this->reports->write($resultado);

        if ($runId !== null) {
            DB::table('import_runs')->where('id', $runId)->update([
                'status' => $resultado['status'], 'finished_at' => CarbonImmutable::now('UTC'),
                'counters' => json_encode($ctx->counters), 'report_path' => $resultado['reports']['json'], 'updated_at' => CarbonImmutable::now('UTC'),
            ]);
        }

        return $resultado;
    }

    private function persistIssues(ImportContext $ctx): void
    {
        foreach (array_chunk($ctx->issues, 200) as $lote) {
            DB::table('import_issues')->insert(array_map(fn ($i) => [
                ...$i,
                'import_run_id' => $ctx->runId,
                'context' => $i['context'] === [] ? null : json_encode($i['context'], JSON_UNESCAPED_UNICODE),
                'created_at' => $ctx->now,
            ], $lote));
        }
    }
}

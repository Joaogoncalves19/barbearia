<?php

namespace Tests\Feature\LegacyImport;

use App\Modules\LegacyImport\LegacyImporter;
use App\Modules\LegacyImport\Reporting\ReportWriter;
use App\Modules\LegacyImport\Steps\Step;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportRunTest extends ImporterTestCase
{
    public function test_simulacao_nao_grava_nada_e_gera_relatorio(): void
    {
        $this->buildSource();
        $r = $this->import(dryRun: true);

        $this->assertSame('rolled_back', $r['status']);
        foreach ($this->domainTables() as $t) {
            $this->assertSame(0, DB::table($t)->count(), "Simulacao gravou em {$t}");
        }
        $this->assertGreaterThan(0, $r['counters']['agendamentos']['imported']);
        foreach ($r['reports'] as $arquivo) {
            Storage::disk('local')->assertExists($arquivo);
        }
        $this->assertStringContainsString('NADA foi gravado', Storage::disk('local')->get($r['reports']['markdown']));
        Storage::disk('local')->assertMissing('legacy-import/archive');
    }

    public function test_simulacao_e_importacao_real_tem_o_mesmo_resultado(): void
    {
        $this->buildSource();
        $simulado = $this->import(dryRun: true);
        $real = $this->import();

        $this->assertSame('completed', $real['status'], $real['error'] ?? '');
        $this->assertSame($simulado['counters'], $real['counters']);
        $this->assertSame(count($simulado['issues']), count($real['issues']));
    }

    public function test_origem_nunca_e_alterada(): void
    {
        $this->buildSource();
        $hash = hash_file('sha256', $this->source);
        $r = $this->import();
        $this->import();
        $this->assertTrue($r['source_unchanged']);
        $this->assertSame($hash, hash_file('sha256', $this->source));

        // A conexao de leitura e somente leitura: escrever e impossivel.
        $pdo = new \PDO('sqlite:'.$this->source, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->expectException(\PDOException::class);
        $pdo->exec("DELETE FROM clientes WHERE id = 'CL-N00001'");
    }

    public function test_importacao_completa_concilia_e_passa_na_integridade(): void
    {
        $this->buildSource();
        $r = $this->import();

        $this->assertSame('completed', $r['status'], $r['error'] ?? '');
        foreach ($r['reconciliation'] as $nome => $v) {
            $this->assertTrue($v['ok'], "Conciliacao {$nome}: ".json_encode($v['detail']));
        }
        $this->assertSame([], $r['integrity']);
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        // Contagem: origem = destino + nao importados (com pendencia).
        foreach (['clientes' => 'customers', 'agendamentos' => 'appointments', 'barbeiros' => 'professionals', 'servicos' => 'services', 'avaliacoes' => 'reviews'] as $origem => $destino) {
            $c = $r['counters'][$origem];
            $this->assertSame($c['read'], $c['imported'] + ($c['skipped'] ?? 0), "Contagem de {$origem}");
        }
        $this->assertSame(30 + 16, DB::table('customers')->count());
        $this->assertSame(1, DB::table('import_runs')->where('status', 'completed')->count());
        $this->assertSame(count($r['issues']), DB::table('import_issues')->count());
        Storage::disk('local')->assertExists('legacy-import/archive/run-'.$r['run_id'].'/admin_crm_clientes.json');
    }

    public function test_segunda_execucao_nao_duplica_nada(): void
    {
        $this->buildSource();
        $this->import();
        $antes = $this->snapshot();

        $r2 = $this->import();
        $this->assertSame('completed', $r2['status'], $r2['error'] ?? '');
        $this->assertSame($antes, $this->snapshot(), 'dados identicos apos reexecucao');
        $this->assertSame(0, array_sum(array_map(fn ($c) => $c['imported'] ?? 0, $r2['counters'])));
        $this->assertSame($r2['counters']['agendamentos']['read'] - $r2['counters']['agendamentos']['skipped'], $r2['counters']['agendamentos']['unchanged']);
    }

    public function test_registro_alterado_na_origem_nao_e_sobrescrito(): void
    {
        $this->buildSource();
        $this->import();
        $pdo = new \PDO('sqlite:'.$this->source);
        $pdo->exec("UPDATE clientes SET nome = 'Nome Alterado' WHERE id = 'CL-N00001'");
        $pdo = null;

        $r = $this->import();
        $this->assertCount(1, $this->issues($r, 'source_changed', 'CL-N00001'));
        $this->assertNotSame('Nome Alterado', DB::table('customers')->where('id', $this->ref('clientes', 'CL-N00001'))->value('name'));
    }

    public function test_falha_no_meio_desfaz_tudo(): void
    {
        $this->buildSource();
        $importador = new class(app(ReportWriter::class), app(IntegrityChecker::class)) extends LegacyImporter
        {
            public function steps(): array
            {
                $quebra = new class extends Step
                {
                    public function name(): string
                    {
                        return 'Etapa que quebra';
                    }

                    public function tables(): array
                    {
                        return [];
                    }

                    protected function handle(): void
                    {
                        throw new RuntimeException('falha simulada');
                    }
                };

                return [...array_slice(parent::steps(), 0, 5), $quebra];
            }
        };

        $r = $importador->run($this->source, false, $this->now);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('falha simulada', $r['error']);
        $this->assertSame(0, DB::table('customers')->count(), 'clientes importados antes da falha foram desfeitos');
        $this->assertSame(0, DB::table('legacy_references')->count());
        $this->assertSame('failed', DB::table('import_runs')->value('status'));
    }

    public function test_instalacao_antiga_sem_colunas_novas(): void
    {
        $this->buildSource(minimal: true);
        $r = $this->import();
        $this->assertSame('completed', $r['status'], $r['error'] ?? '');
        $this->assertGreaterThan(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('payments')->where('method', '<>', 'unknown')->count(), 'sem forma_pagamento a origem nao informa o metodo');
        $this->assertSame(0, DB::table('payments')->where('tip_cents', '>', 0)->count(), 'sem coluna de gorjeta nao se inventa gorjeta');
        $this->assertSame(0, DB::table('appointment_reminders')->count());
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->domainTables() as $t) {
            if (in_array($t, ['import_runs', 'import_issues'], true)) {
                continue;
            }
            $out[$t] = md5(json_encode(DB::table($t)->get()->map(fn ($r) => (array) $r)->all()));
        }

        return $out;
    }
}

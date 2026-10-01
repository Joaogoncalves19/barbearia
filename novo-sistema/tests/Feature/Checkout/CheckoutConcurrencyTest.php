<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Models\Professional;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONCORRENCIA DE VERDADE (item 34 do briefing): varios processos PHP
 * independentes, cada um com a sua conexao, num banco SQLite em arquivo
 * (como em producao), disputam:
 *
 * - concluir o MESMO atendimento (cada um com a sua chave, como dois
 *   usuarios em telas diferentes);
 * - tirar do estoque as ultimas unidades do MESMO produto;
 * - fechar o MESMO caixa.
 *
 * Cada processo segura a transacao aberta na janela de corrida. Esperado:
 * nenhuma duplicidade, nenhum saldo negativo, nada gravado pela metade.
 */
class CheckoutConcurrencyTest extends TestCase
{
    private string $dir;

    private string $db;

    private User $ator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'caixa-concorrencia-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.DIRECTORY_SEPARATOR.'caixa.sqlite';
        touch($this->db);

        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->ator = User::factory()->manager()->create();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_dois_usuarios_concluindo_o_mesmo_atendimento(): void
    {
        $servico = Service::factory()->create(['price_cents' => 5000]);
        $pro = Professional::factory()->create();
        $pro->services()->attach($servico->id);
        $pomada = Product::factory()->create(['price_cents' => 3500]);
        app(StockLedger::class)->receive($pomada, 5, null, 'Estoque inicial', $this->ator);
        app(CashRegister::class)->open(0, null, $this->ator);

        // O encaixe ocupa a agenda: abre com a barbearia funcionando (relogio
        // parado so neste processo; os filhos concluem no relogio real).
        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
        }
        $this->travelTo(BusinessTime::at('2026-10-05', '10:02'));

        $svc = app(AttendanceService::class);
        $at = $svc->openWalkIn($servico, $pro, null, 'Cliente Fictício', null, $this->ator);
        $svc->addProduct($at, $pomada, 1, $this->ator);
        $svc->start($at, $this->ator);
        $this->travelBack();

        $saidas = $this->race(4, ['complete', $at->id, $this->ator->id]);

        $this->assertCount(1, $this->starting('OK', $saidas), "uma conclusão vence:\n".implode("\n", $saidas));
        $this->assertCount(3, $this->starting('RULE invalid_status', $saidas), "as outras veem o atendimento já concluído:\n".implode("\n", $saidas));

        DB::purge('sqlite');
        $this->assertSame(AttendanceStatus::Completed, Attendance::query()->findOrFail($at->id)->status);
        $this->assertSame(1, Payment::query()->count(), 'um pagamento');
        $this->assertSame(1, CashMovement::query()->count(), 'uma entrada no caixa');
        $this->assertSame(1, StockMovement::query()->where('attendance_id', $at->id)->count(), 'uma baixa');
        $this->assertSame(4, app(StockLedger::class)->balance($pomada));
    }

    public function test_varios_tirando_as_ultimas_unidades_do_estoque(): void
    {
        $p = Product::factory()->create();
        app(StockLedger::class)->receive($p, 3, null, 'Estoque inicial', $this->ator);

        $saidas = $this->race(5, ['stock', $p->id, $this->ator->id]);

        $this->assertCount(3, $this->starting('OK', $saidas), implode("\n", $saidas));
        $this->assertCount(2, $this->starting('RULE insufficient', $saidas), implode("\n", $saidas));

        DB::purge('sqlite');
        $this->assertSame(0, app(StockLedger::class)->balance($p), 'nunca negativo');
        $saldos = StockMovement::query()->where('product_id', $p->id)->orderBy('id')->pluck('balance_after')->all();
        $this->assertSame([3, 2, 1, 0], $saldos, 'cada lançamento viu o saldo do anterior');
    }

    public function test_fechamento_simultaneo_do_mesmo_caixa(): void
    {
        $s = app(CashRegister::class)->open(10000, null, $this->ator);

        $saidas = $this->race(3, ['close', $s->id, $this->ator->id]);

        $this->assertCount(1, $this->starting('OK', $saidas), implode("\n", $saidas));
        $this->assertCount(2, $this->starting('RULE closed', $saidas), implode("\n", $saidas));

        DB::purge('sqlite');
        $fechado = CashSession::query()->findOrFail($s->id);
        $this->assertSame([10000, 0], [$fechado->counted_cash_cents, $fechado->difference_cents]);
    }

    /**
     * @param  list<int|string>  $args
     * @return list<string>
     */
    private function race(int $n, array $args): array
    {
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada-'.bin2hex(random_bytes(3));
        $processos = [];
        for ($i = 0; $i < $n; $i++) {
            $processos[] = $this->spawn([...$args, "--barrier={$largada}", '--hold=300']);
        }
        usleep(1_500_000); // todos sobem e ficam esperando a largada
        touch($largada);

        return array_map(fn ($p) => $this->finish($p), $processos);
    }

    /**
     * @param  list<string>  $saidas
     * @return list<string>
     */
    private function starting(string $prefix, array $saidas): array
    {
        return array_values(array_filter($saidas, fn ($s) => str_starts_with($s, $prefix)));
    }

    /**
     * @param  list<int|string>  $args
     * @return array{proc: resource, out: resource, err: resource}
     */
    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'app:checkout-probe'], array_map('strval', $args));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->db,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc);

        return ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
    }

    /**
     * @param  array{proc: resource, out: resource, err: resource}  $p
     */
    private function finish(array $p): string
    {
        $saida = trim((string) stream_get_contents($p['out']));
        $erro = trim((string) stream_get_contents($p['err']));
        fclose($p['out']);
        fclose($p['err']);
        proc_close($p['proc']);

        return $saida !== '' ? $saida : 'SEM SAIDA: '.$erro;
    }
}

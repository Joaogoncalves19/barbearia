<?php

namespace Tests\Feature\Promotions;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONCORRENCIA DE VERDADE nas promocoes: processos PHP independentes (cada
 * um com a sua conexao, banco SQLite em arquivo) disputam o mesmo cupom de
 * uso limitado e os mesmos pontos, em horarios diferentes (a agenda nao e o
 * que decide aqui). Esperado: o limite nunca e ultrapassado, os pontos nunca
 * sao prometidos duas vezes, e quem perde nao deixa nada gravado.
 */
class PromotionConcurrencyTest extends TestCase
{
    private string $dir;

    private string $db;

    private Service $servico;

    private Professional $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'promocoes-concorrencia-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.DIRECTORY_SEPARATOR.'promocoes.sqlite';
        touch($this->db);

        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
        }
        $this->servico = Service::factory()->create(['duration_minutes' => 30, 'price_cents' => 5000]);
        $this->pro = Professional::factory()->create();
        $this->pro->services()->attach($this->servico->id);
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

    public function test_cupom_de_um_uso_disputado_por_cinco_clientes(): void
    {
        $cupom = Coupon::query()->create(['code' => 'UNICO', 'discount_type' => 'percent', 'percent_bp' => 2000, 'max_uses' => 1, 'is_active' => true]);
        $clientes = Customer::factory()->count(5)->create();

        $saidas = $this->race($clientes->map(fn (Customer $c, int $i) => [$c->id, ['10:00', '10:30', '11:00', '11:30', '12:00'][$i], '--coupon=UNICO'])->all());

        $ok = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')));
        $limite = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'PROMO promotion_rejected') && str_contains($s, 'limite de usos')));
        $this->assertCount(1, $ok, "um vence:\n".implode("\n", $saidas));
        $this->assertCount(4, $limite, "os outros: limite de usos:\n".implode("\n", $saidas));

        DB::purge('sqlite');
        $this->assertSame([1, 1, 1], [(int) $cupom->fresh()?->uses_count, CouponRedemption::query()->count(), Appointment::query()->count()]);
        $this->assertSame(4000, Appointment::query()->sole()->total_cents);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_os_mesmos_pontos_pedidos_em_tres_agendamentos_ao_mesmo_tempo(): void
    {
        PromotionPolicy::save(['loyalty_enabled' => true, 'loyalty_points_required' => 10, 'loyalty_reward_type' => 'percent', 'loyalty_reward_percent_bp' => 5000], null);
        $cliente = Customer::factory()->create();
        app(LoyaltyLedger::class)->credit($cliente, 15, LoyaltyEntryKind::Adjustment, 'Pontos de teste');

        $saidas = $this->race([[$cliente->id, '10:00', '--points'], [$cliente->id, '11:00', '--points'], [$cliente->id, '12:00', '--points']]);

        $ok = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')));
        $sem = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'PROMO promotion_rejected Pontos insuficientes')));
        $this->assertCount(1, $ok, "um vence:\n".implode("\n", $saidas));
        $this->assertCount(2, $sem, "os outros: pontos insuficientes:\n".implode("\n", $saidas));

        DB::purge('sqlite');
        $ledger = app(LoyaltyLedger::class);
        $this->assertSame([1, 1, 15, 5], [LoyaltyRedemption::query()->count(), Appointment::query()->count(), $ledger->balance($cliente), $ledger->available($cliente)]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $tentativas  cliente, hora, opcao
     * @return list<string>
     */
    private function race(array $tentativas): array
    {
        $dia = CarbonImmutable::now(BusinessTime::zone())->addDays(3)->toDateString();
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada';

        $processos = [];
        foreach ($tentativas as [$cliente, $hora, $opcao]) {
            $processos[] = $this->spawn([$this->servico->id, $this->pro->id, $cliente, BusinessTime::at($dia, $hora)->toIso8601String(), "--barrier={$largada}", '--hold=300', $opcao]);
        }
        usleep(1_500_000);
        touch($largada);

        return array_map(fn ($p) => $this->finish($p), $processos);
    }

    /**
     * @param  list<int|string>  $args
     * @return array{proc: resource, out: resource, err: resource}
     */
    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'app:booking-probe'], array_map('strval', $args));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
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

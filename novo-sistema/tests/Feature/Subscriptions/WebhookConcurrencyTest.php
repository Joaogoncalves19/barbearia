<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\System\Integrity\IntegrityChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONCORRENCIA DE VERDADE nos webhooks: processos PHP independentes (cada um
 * com a sua conexao, SQLite em arquivo) entregam eventos da MESMA assinatura
 * ao mesmo tempo. Esperado: o mesmo evento aplica uma vez; fatura repetida
 * nao duplica pagamento; o estado consolidado e o da fotografia mais nova; o
 * direito e o maior pago; nada fica falho ou pela metade.
 */
class WebhookConcurrencyTest extends TestCase
{
    private string $dir;

    private string $db;

    private string $secret;

    private Subscription $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'webhooks-concorrencia-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.DIRECTORY_SEPARATOR.'webhooks.sqlite';
        touch($this->db);
        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $this->secret = 'whsec_teste_'.Str::random(32);
        config(['services.stripe.webhook_secret' => $this->secret]);

        // Assinatura ativa (adesao paga), como se os eventos de adesao ja tivessem chegado.
        $plano = Plan::factory()->withVersion(9900)->create();
        $this->s = Subscription::query()->create([
            'customer_id' => Customer::factory()->create()->id, 'plan_id' => $plano->id, 'plan_version_id' => $plano->currentVersion?->id,
            'status' => SubscriptionStatus::Active, 'origin' => SubscriptionOrigin::Panel, 'gateway' => Gateway::Stripe,
            'gateway_subscription_id' => 'sub_CONC_1', 'gateway_customer_id' => 'cus_CONC_1', 'starts_on' => '2026-10-05', 'ends_on' => '2026-11-05',
            'activated_at' => now(), 'gateway_synced_at' => CarbonImmutable::now()->subDay(),
        ]);
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

    public function test_mesmo_evento_entregue_por_quatro_processos_aplica_uma_vez(): void
    {
        $evento = $this->event('evt_CONC_RENOVA', 'invoice.paid', $this->renewal('in_CONC_2'), 0);

        $saidas = $this->race(array_fill(0, 4, $evento));

        $this->assertCount(4, array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')), implode("\n", $saidas));
        $this->assertCount(1, array_filter($saidas, fn ($s) => $s === 'OK applied'), "um aplica:\n".implode("\n", $saidas));
        DB::purge('sqlite');
        $this->assertSame([1, 1, '2026-12-05'], [SubscriptionPayment::query()->count(), GatewayEvent::query()->count(), $this->s->refresh()->ends_on?->toDateString()]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_eventos_diferentes_da_mesma_assinatura_ao_mesmo_tempo_consolidam_o_estado_certo(): void
    {
        $fatura = $this->renewal('in_CONC_3');
        $eventos = [
            $this->event('evt_A_paga', 'invoice.paid', $fatura, 10),
            $this->event('evt_B_mesma_fatura', 'invoice.payment_succeeded', $fatura, 11),
            $this->event('evt_C_cancela_novo', 'customer.subscription.updated', $this->snapshot(true), 30),
            $this->event('evt_D_ativa_velho', 'customer.subscription.updated', $this->snapshot(false), 20),
            $this->event('evt_E_falha_velha', 'invoice.payment_failed', [...$fatura, 'amount_paid' => 0, 'attempt_count' => 1], 5),
        ];

        $saidas = $this->race($eventos);

        $this->assertCount(5, array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')), implode("\n", $saidas));
        DB::purge('sqlite');
        $s = $this->s->refresh();
        $this->assertSame(1, SubscriptionPayment::query()->count(), 'a fatura repetida não duplica o pagamento');
        $this->assertSame('2026-12-05', $s->ends_on?->toDateString(), 'direito estendido pela fatura paga');
        // A fotografia mais nova (cancelamento agendado) vence, qualquer que seja a ordem de chegada.
        $this->assertSame(SubscriptionStatus::CancelScheduled, $s->status, "estado final:\n".implode("\n", $saidas));
        $this->assertSame(0, GatewayEvent::query()->where('status', '<>', 'processed')->count(), 'nenhum evento falho');
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    /**
     * @return array<string, mixed>
     */
    private function renewal(string $id): array
    {
        return [
            'id' => $id, 'object' => 'invoice', 'subscription' => 'sub_CONC_1', 'customer' => 'cus_CONC_1', 'amount_paid' => 9900, 'currency' => 'brl',
            'billing_reason' => 'subscription_cycle', 'payment_intent' => 'pi_'.$id,
            'lines' => ['data' => [['period' => ['start' => BusinessTime::at('2026-11-05', '08:00')->getTimestamp(), 'end' => BusinessTime::at('2026-12-05', '08:00')->getTimestamp()]]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(bool $cancel): array
    {
        return ['id' => 'sub_CONC_1', 'object' => 'subscription', 'customer' => 'cus_CONC_1', 'status' => 'active', 'cancel_at_period_end' => $cancel,
            'current_period_end' => BusinessTime::at('2026-12-05', '08:00')->getTimestamp(), 'metadata' => ['local_subscription' => $this->s->public_id]];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, array $object, int $minute): array
    {
        return ['id' => $id, 'type' => $type, 'created' => CarbonImmutable::now()->startOfDay()->addMinutes($minute)->getTimestamp(), 'livemode' => false, 'data' => ['object' => $object]];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function race(array $events): array
    {
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada';
        $processos = [];
        foreach ($events as $i => $e) {
            $arquivo = $this->dir.DIRECTORY_SEPARATOR.'evento-'.$i.'.json';
            file_put_contents($arquivo, (string) json_encode($e));
            $processos[] = $this->spawn([$arquivo, "--barrier={$largada}", '--hold=300']);
        }
        usleep(1_500_000);
        touch($largada);

        return array_map(fn ($p) => $this->finish($p), $processos);
    }

    /**
     * @param  list<string>  $args
     * @return array{proc: resource, out: resource, err: resource}
     */
    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'app:webhook-probe'], $args);
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'STRIPE_WEBHOOK_SECRET' => $this->secret,
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

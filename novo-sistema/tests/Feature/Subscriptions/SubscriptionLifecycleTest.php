<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Models\SubscriptionEvent;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\Plans;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Ciclo da assinatura pelos eventos do Stripe (assinaturas.md §3): adesao,
 * ativacao, renovacao, falha, recuperacao, cancelamento agendado e
 * imediato, reativacao, expiracao e eventos fora de ordem. Estado e direito
 * ao beneficio sao separados.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
    }

    private function benefitOn(string $date): bool
    {
        return app(SubscriptionBenefits::class)->rightOn($this->cliente->id, $date) !== null;
    }

    public function test_adesao_cria_aguardando_pagamento_sem_beneficio_e_sessao_correta_no_stripe(): void
    {
        $s = $this->startSignup($this->cliente);

        $this->assertSame([SubscriptionStatus::Pending, null], [$s->status, $s->ends_on]);
        $this->assertNotNull($s->checkout_url);
        $this->assertFalse($this->benefitOn('2026-10-05'), 'aguardando pagamento não dá benefício');
        Http::assertSent(function ($r) use ($s) {
            $corpo = $r->data();

            return str_ends_with($r->url(), '/v1/checkout/sessions')
                && $r->hasHeader('Idempotency-Key', 'checkout-'.$s->public_id)
                && $r->hasHeader('Stripe-Version', '2024-06-20')
                && ($corpo['mode'] ?? null) === 'subscription'
                && ($corpo['metadata']['local_subscription'] ?? null) === $s->public_id
                && ($corpo['subscription_data']['metadata']['local_subscription'] ?? null) === $s->public_id
                && (int) ($corpo['line_items'][0]['price_data']['unit_amount'] ?? 0) === 9900
                && ($corpo['line_items'][0]['price_data']['recurring']['interval'] ?? null) === 'month'
                && str_starts_with((string) ($corpo['success_url'] ?? ''), (string) config('app.url'))
                && ! str_contains((string) json_encode($corpo), (string) config('services.stripe.secret'));
        });
        $this->assertSame(['created', 'checkout_started'], SubscriptionEvent::query()->orderBy('id')->pluck('kind')->map->value->all());
    }

    public function test_pagamento_confirmado_ativa_e_da_direito_ate_o_fim_pago(): void
    {
        $s = $this->activeSubscription($this->cliente);

        $this->assertSame([SubscriptionStatus::Active, '2026-11-05', '2026-10-05'], [$s->status, $s->ends_on?->toDateString(), $s->starts_on?->toDateString()]);
        $this->assertNotNull($s->activated_at);
        $p = SubscriptionPayment::query()->sole();
        $this->assertSame([9900, 'paid', 'signup', 'stripe'], [$p->amount_cents, $p->status, $p->kind, $p->gateway]);
        $this->assertTrue($this->benefitOn('2026-11-05'), 'vale no último dia pago');
        $this->assertTrue($this->benefitOn('2026-11-06'), '1 dia de tolerância (R-28) enquanto renova');
        $this->assertFalse($this->benefitOn('2026-11-07'));
        $this->assertSame('sub_'.substr($s->public_id, 0, 8), $s->gateway_subscription_id, 'ID do Stripe preservado');
    }

    public function test_assinatura_nao_mexe_no_caixa_nem_na_comissao(): void
    {
        $antes = [CashMovement::query()->count(), CommissionEntry::query()->count()];
        $this->activeSubscription($this->cliente);

        $this->assertSame($antes, [CashMovement::query()->count(), CommissionEntry::query()->count()]);
    }

    public function test_renovacao_estende_e_a_mesma_fatura_nao_estende_duas_vezes(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $renov = $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle');

        $this->postWebhook($this->event('invoice.paid', $renov))->assertOk();
        $this->postWebhook($this->event('invoice.paid', $renov))->assertOk();

        $this->assertSame('2026-12-05', $s->refresh()->ends_on?->toDateString());
        $this->assertSame([2, 'renewal'], [SubscriptionPayment::query()->count(), SubscriptionPayment::query()->latest('id')->first()?->kind]);
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'renewed')->count());
    }

    public function test_fatura_antiga_chegando_depois_nao_encurta_o_direito(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle')))->assertOk();

        // Reenvio atrasado da fatura de adesao (outro evento, mesma fatura).
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s), $this->ts('2026-10-05 08:00')))->assertOk();

        $this->assertSame('2026-12-05', $s->refresh()->ends_on?->toDateString());
        $this->assertSame(2, SubscriptionPayment::query()->count());
    }

    public function test_falha_de_pagamento_atraso_e_recuperacao_sem_revogar_o_beneficio_pago(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $renov = $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle', amount: 9900);

        $this->postWebhook($this->event('invoice.payment_failed', [...$renov, 'amount_paid' => 0, 'attempt_count' => 1, 'next_payment_attempt' => $this->ts('2026-11-08 08:00')]))->assertOk();
        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'past_due', periodEnd: $this->ts('2026-12-05 08:00'))))->assertOk();

        $s->refresh();
        $this->assertSame([SubscriptionStatus::PastDue, '2026-11-05'], [$s->status, $s->ends_on?->toDateString()], 'atraso não estende nem revoga');
        $this->assertTrue($this->benefitOn('2026-11-05'), 'benefício até a data paga');
        $this->assertFalse($this->benefitOn('2026-11-06'), 'em atraso, sem tolerância');
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'payment_failed')->count());
        $this->assertSame(1, SubscriptionPayment::query()->count(), 'tentativa recusada não é pagamento');

        $this->postWebhook($this->event('invoice.paid', $renov))->assertOk();
        $s->refresh();
        $this->assertSame([SubscriptionStatus::Active, '2026-12-05'], [$s->status, $s->ends_on?->toDateString()]);
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'recovered')->count());
    }

    public function test_pagamento_pendente_so_registra_no_historico(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $renov = $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle');

        $this->postWebhook($this->event('invoice.payment_action_required', [...$renov, 'amount_paid' => 0]))->assertOk();

        $this->assertSame([SubscriptionStatus::Active, '2026-11-05'], [$s->refresh()->status, $s->ends_on?->toDateString()]);
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'payment_pending')->count());
    }

    public function test_cancelamento_agendado_reativacao_e_fim_no_periodo(): void
    {
        $s = $this->activeSubscription($this->cliente);

        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'active', ['cancel_at_period_end' => true]), $this->ts('2026-10-10 08:00')))->assertOk();
        $this->assertSame([SubscriptionStatus::CancelScheduled, 'stripe'], [$s->refresh()->status, $s->cancel_source?->value]);
        $this->assertTrue($this->benefitOn('2026-11-05'));
        $this->assertFalse($this->benefitOn('2026-11-06'), 'cancelamento agendado: sem tolerância');

        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'active'), $this->ts('2026-10-11 08:00')))->assertOk();
        $this->assertSame([SubscriptionStatus::Active, null], [$s->refresh()->status, $s->cancel_source]);
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'reactivated')->count());

        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'active', ['cancel_at_period_end' => true]), $this->ts('2026-10-12 08:00')))->assertOk();
        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-11-05 08:00'), 'canceled_at' => $this->ts('2026-10-12 08:00')]), $this->ts('2026-11-05 08:01')))->assertOk();

        $s->refresh();
        $this->assertSame([SubscriptionStatus::Cancelled, '2026-11-05', '2026-11-05'], [$s->status, $s->ends_on?->toDateString(), $s->cancel_effective_on?->toDateString()], 'no fim do período: direito até o fim pago');
        $this->assertNull($s->active_customer_id, 'libera a vaga de assinatura vigente');
    }

    public function test_cancelamento_imediato_pelo_stripe_encerra_o_direito_hoje(): void
    {
        $s = $this->activeSubscription($this->cliente);

        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-10-05 08:00'), 'canceled_at' => $this->ts('2026-10-05 08:00')])))->assertOk();

        $s->refresh();
        $this->assertSame([SubscriptionStatus::Cancelled, '2026-10-04'], [$s->status, $s->ends_on?->toDateString()]);
        $this->assertFalse($this->benefitOn('2026-10-05'));
    }

    public function test_inadimplencia_final_vira_cancelada_e_adesao_nunca_paga_expira(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'unpaid'), $this->ts('2026-11-20 08:00')))->assertOk();
        $this->assertSame(SubscriptionStatus::PastDue, $s->refresh()->status);
        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-11-25 08:00')]), $this->ts('2026-11-25 08:00')))->assertOk();
        $this->assertSame([SubscriptionStatus::Cancelled, '2026-11-05'], [$s->refresh()->status, $s->ends_on?->toDateString()], 'cancelada depois do fim pago: não muda o passado');

        $outro = Customer::factory()->create();
        $p = $this->startSignup($outro);
        $this->postWebhook($this->event('customer.subscription.updated', [...$this->subObject($p, 'incomplete_expired')]))->assertOk();
        $this->assertSame(SubscriptionStatus::Expired, $p->refresh()->status);
    }

    public function test_link_expirado_no_stripe_expira_a_adesao(): void
    {
        $s = $this->startSignup($this->cliente);

        $this->postWebhook($this->event('checkout.session.expired', ['id' => $s->checkout_session_id, 'object' => 'checkout.session', 'client_reference_id' => $s->public_id, 'metadata' => ['local_subscription' => $s->public_id]]))->assertOk();

        $this->assertSame(SubscriptionStatus::Expired, $s->refresh()->status);
        $this->assertNull($s->active_customer_id);
    }

    public function test_fotografia_antiga_nao_sobrescreve_a_nova(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $novo = $this->event('customer.subscription.updated', $this->subObject($s, 'active', ['cancel_at_period_end' => true]), $this->ts('2026-10-10 09:00'));
        $velho = $this->event('customer.subscription.updated', $this->subObject($s, 'active'), $this->ts('2026-10-10 08:00'));

        $this->postWebhook($novo)->assertOk();
        $this->postWebhook($velho)->assertOk()->assertJson(['result' => 'stale']);

        $this->assertSame(SubscriptionStatus::CancelScheduled, $s->refresh()->status);
    }

    public function test_transicao_invalida_nao_e_aplicada(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-10-05 08:00')]), $this->ts('2026-10-05 08:00')))->assertOk();

        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'active'), $this->ts('2026-10-06 08:00')))->assertOk();

        $this->assertSame(SubscriptionStatus::Cancelled, $s->refresh()->status, 'cancelada não volta a ativa');
        $this->assertStringContainsString('não aplicada', (string) SubscriptionEvent::query()->latest('id')->first()?->reason);
    }

    public function test_versao_do_plano_nao_muda_quem_ja_assina(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $dono = User::factory()->owner()->create();
        app(Plans::class)->newVersion($this->clube, 12900, [$this->corte->id, $this->barba->id], 'Reajuste', $dono);

        $this->assertSame(9900, $s->refresh()->planVersion?->price_cents);
        $this->assertSame([$this->corte->id], app(SubscriptionBenefits::class)->coveredServiceIds($s));
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle')))->assertOk();
        $this->assertSame(9900, SubscriptionPayment::query()->latest('id')->first()?->amount_cents, 'renovação pelo preço contratado (o Stripe cobra o preço da adesão)');
        $this->assertSame([], app(IntegrityChecker::class)->violations());
        $this->assertSame(0, GatewayEvent::query()->where('status', '<>', 'processed')->count());
    }
}

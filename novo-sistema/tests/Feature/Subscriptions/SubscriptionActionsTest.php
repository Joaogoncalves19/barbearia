<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionRefund;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Acoes sobre a assinatura (cancelamentos.md, reembolsos.md): o Stripe
 * primeiro (idempotente), o estado local pela resposta; sem confirmacao do
 * Stripe nada muda. Permissao especifica por acao; cliente so a propria.
 */
class SubscriptionActionsTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private User $dono;

    private User $gerente;

    private User $financeiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        $this->dono = User::factory()->owner()->create();
        $this->gerente = User::factory()->manager()->create();
        $this->financeiro = User::factory()->role(StaffRole::Finance)->create();
    }

    private function as(User $u): static
    {
        $this->flushSession();

        return $this->actingAs($u, 'web');
    }

    /** Stripe responde a assinatura conforme o pedido (cancelar no fim do periodo ou desfazer). */
    private function stripeSub(Subscription $s): void
    {
        Http::fake(['stripe.teste/v1/subscriptions/*' => fn ($r) => Http::response($this->subObject($s, 'active', ['cancel_at_period_end' => ($r->data()['cancel_at_period_end'] ?? 'false') === 'true']))]);
    }

    // --- Cancelar e reativar ---------------------------------------------------------------------

    public function test_cancelar_no_fim_do_periodo_pelo_painel(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->stripeSub($s);

        $this->as($this->gerente)->post(route('panel.subscriptions.cancel', $s), ['mode' => 'end', 'reason' => 'Mudou de cidade'])->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame([SubscriptionStatus::CancelScheduled, 'staff', 'Mudou de cidade', $this->gerente->id, '2026-11-05'],
            [$s->status, $s->cancel_source?->value, $s->cancel_reason, $s->cancelled_by_user_id, $s->ends_on?->toDateString()]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/subscriptions/'.$s->gateway_subscription_id) && ($r->data()['cancel_at_period_end'] ?? null) === 'true'
            && $r->hasHeader('Idempotency-Key'));
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.cancel_scheduled')->count());
    }

    public function test_cancelar_imediatamente_encerra_o_direito_hoje(): void
    {
        $s = $this->activeSubscription($this->cliente);
        Http::fake(['stripe.teste/v1/subscriptions/*' => Http::response($this->subObject($s, 'canceled', ['ended_at' => now()->getTimestamp(), 'canceled_at' => now()->getTimestamp()]))]);

        $this->manager()->cancel($s, true, 'Fraude no cartão', $this->dono);

        $s->refresh();
        $this->assertSame([SubscriptionStatus::Cancelled, '2026-10-04', '2026-10-05'], [$s->status, $s->ends_on?->toDateString(), $s->cancel_effective_on?->toDateString()]);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.cancelled')->count());
    }

    public function test_stripe_recusando_nada_muda(): void
    {
        $s = $this->activeSubscription($this->cliente);
        Http::fake(['stripe.teste/*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'resource_missing', 'message' => 'x']], 404)]);

        try {
            $this->manager()->cancel($s, false, 'Teste', $this->dono);
            $this->fail('deveria recusar');
        } catch (SubscriptionRuleViolation $e) {
            $this->assertSame('gateway_error', $e->reason);
        }
        $this->assertSame([SubscriptionStatus::Active, null], [$s->refresh()->status, $s->cancel_reason]);
    }

    public function test_motivo_obrigatorio_e_cliente_so_cancela_a_renovacao(): void
    {
        $s = $this->activeSubscription($this->cliente);

        $this->as($this->gerente)->post(route('panel.subscriptions.cancel', $s), ['mode' => 'end', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->expectException(SubscriptionRuleViolation::class);
        $this->manager()->cancel($s, true, null, $this->cliente);
    }

    public function test_cliente_cancela_e_desfaz_pela_conta(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->stripeSub($s);
        $this->actingAs($this->cliente, 'customer')->post(route('account.subscription.cancel'))->assertSessionHas('status');
        $this->assertSame([SubscriptionStatus::CancelScheduled, 'customer'], [$s->refresh()->status, $s->cancel_source?->value]);

        $this->actingAs($this->cliente, 'customer')->post(route('account.subscription.reactivate'))->assertSessionHas('status');
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status);
        Http::assertSent(fn ($r) => ($r->data()['cancel_at_period_end'] ?? null) === 'false');

        $this->actingAs($this->cliente, 'customer')->get(route('account.subscription'))->assertOk()->assertSee('Clube do Corte')->assertSee('05/11/2026');
    }

    public function test_assinatura_manual_importada_cancela_e_expira_sem_stripe(): void
    {
        $s = Subscription::factory()->create(['customer_id' => $this->cliente->id, 'plan_id' => $this->clube->id, 'ends_on' => '2026-10-20']);
        $this->manager()->cancel($s, false, 'Pedido no balcão', $this->gerente);
        $this->assertSame(SubscriptionStatus::CancelScheduled, $s->refresh()->status);
        Http::assertNothingSent();

        $this->travelTo(now()->setDate(2026, 10, 21));
        Artisan::call('app:subscriptions-expire');
        $this->assertSame(SubscriptionStatus::Cancelled, $s->refresh()->status);

        $outra = Subscription::factory()->create(['plan_id' => $this->clube->id, 'ends_on' => '2026-10-19']);
        Artisan::call('app:subscriptions-expire');
        Artisan::call('app:subscriptions-expire');
        $this->assertSame(SubscriptionStatus::Expired, $outra->refresh()->status, 'passou da tolerância de 1 dia');
        $this->assertSame(1, $outra->events()->where('kind', 'expired')->count(), 'rodar de novo não repete');
    }

    public function test_link_vencido_expira_na_rotina(): void
    {
        $s = $this->startSignup($this->cliente);
        $this->travelTo(now()->addDays(2));

        Artisan::call('app:subscriptions-expire');

        $this->assertSame(SubscriptionStatus::Expired, $s->refresh()->status);
    }

    // --- Link de pagamento -----------------------------------------------------------------------

    public function test_link_pelo_painel_e_novo_link_expira_o_anterior(): void
    {
        $this->fakeStripe();
        $this->as($this->recepcao)->get(route('panel.subscriptions.link', ['busca' => $this->cliente->name]))->assertOk()->assertSee($this->cliente->name);
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $this->cliente->public_id, 'plan_id' => $this->clube->id])->assertSessionHasNoErrors();
        $primeiro = Subscription::query()->sole();
        $this->as($this->recepcao)->get(route('panel.subscriptions.show', $primeiro))->assertOk()->assertSee((string) $primeiro->checkout_url, false);

        // Duplo clique: o mesmo link, nenhuma sessao nova no Stripe.
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $this->cliente->public_id, 'plan_id' => $this->clube->id])->assertSessionHasNoErrors();
        $this->assertSame(1, Subscription::query()->count());
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/v1/checkout/sessions')));

        // Um novo link mais tarde substitui o anterior.
        $this->travelTo(now()->addMinutes(5));
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $this->cliente->public_id, 'plan_id' => $this->clube->id])->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionStatus::Expired, $primeiro->refresh()->status);
        $this->assertSame(1, Subscription::query()->where('status', 'pending')->count(), 'nunca dois links pagáveis');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/expire'));
        $this->assertSame(2, AuditLog::query()->where('action', 'subscription.link_created')->count());
    }

    public function test_link_recusado_para_quem_ja_assina_sem_email_ou_sem_stripe(): void
    {
        $this->activeSubscription($this->cliente);
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $this->cliente->public_id, 'plan_id' => $this->clube->id])->assertSessionHasErrors('subscription');

        $semEmail = Customer::factory()->create(['email' => null]);
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $semEmail->public_id, 'plan_id' => $this->clube->id])->assertSessionHasErrors('subscription');

        config(['services.stripe.secret' => '']);
        $outro = Customer::factory()->create();
        $this->as($this->recepcao)->post(route('panel.subscriptions.link.store'), ['customer' => $outro->public_id, 'plan_id' => $this->clube->id])->assertSessionHasErrors('subscription');
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_stripe_fora_ao_gerar_o_link_nao_deixa_adesao_pendurada(): void
    {
        Http::fake(['stripe.teste/*' => Http::response(['error' => ['type' => 'api_error']], 500)]);

        try {
            $this->checkout()->start($this->cliente, $this->clube, SubscriptionOrigin::Panel, null, $this->recepcao);
            $this->fail('deveria recusar');
        } catch (SubscriptionRuleViolation) {
        }

        $this->assertSame([SubscriptionStatus::Expired, null], [Subscription::query()->sole()->status, Subscription::query()->sole()->active_customer_id]);
    }

    // --- Reembolso -------------------------------------------------------------------------------

    public function test_reembolso_parcial_e_total_idempotente_e_sem_passar_do_pago(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $p = SubscriptionPayment::query()->sole();
        Http::fake(['stripe.teste/v1/refunds' => fn ($r) => Http::response(['id' => 're_'.Str::random(8), 'status' => 'succeeded', 'amount' => $r->data()['amount']])]);
        $chave = (string) Str::uuid();

        $this->as($this->financeiro)->post(route('panel.subscriptions.refund', $p), ['request_key' => $chave, 'amount' => '40,00', 'reason' => 'Cobrança indevida'])->assertSessionHasNoErrors();
        $this->as($this->financeiro)->post(route('panel.subscriptions.refund', $p), ['request_key' => $chave, 'amount' => '40,00', 'reason' => 'Cobrança indevida']);
        $this->assertSame(1, SubscriptionRefund::query()->count(), 'duplo clique: um reembolso');
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/v1/refunds')), 'um pedido de reembolso ao Stripe');

        $this->as($this->financeiro)->post(route('panel.subscriptions.refund', $p), ['request_key' => (string) Str::uuid(), 'amount' => '60,00', 'reason' => 'Demais'])->assertSessionHasErrors('refund');
        $this->as($this->financeiro)->post(route('panel.subscriptions.refund', $p), ['request_key' => (string) Str::uuid(), 'amount' => '59,00', 'reason' => 'Resto'])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame([9900, 9900, 0], [$p->amount_cents, $p->refundedCents(), $p->refundableCents()], 'pagamento original intacto');
        $r = SubscriptionRefund::query()->first();
        $this->assertSame(['succeeded', 'staff', $this->financeiro->id, 'Cobrança indevida'], [$r?->status, $r?->source, $r?->requested_by_user_id, $r?->reason]);
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status, 'reembolso não cancela sozinho');
        $this->assertSame(2, AuditLog::query()->where('action', 'subscription.refunded')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_reembolso_recusado_pelo_stripe_nao_conta(): void
    {
        $this->activeSubscription($this->cliente);
        $p = SubscriptionPayment::query()->sole();
        Http::fake(['stripe.teste/v1/refunds' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded']], 400)]);

        try {
            $this->manager()->refund($p, 9900, 'Teste', $this->financeiro, (string) Str::uuid());
            $this->fail('deveria recusar');
        } catch (SubscriptionRuleViolation) {
        }

        $this->assertSame(['failed', 9900], [SubscriptionRefund::query()->sole()->status, $p->refundableCents()]);
    }

    public function test_webhook_do_mesmo_reembolso_nao_duplica_e_reembolso_feito_no_stripe_e_registrado(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $p = SubscriptionPayment::query()->sole();
        Http::fake(['stripe.teste/v1/refunds' => Http::response(['id' => 're_meu', 'status' => 'pending', 'amount' => 1000])]);
        $r = $this->manager()->refund($p, 1000, 'Parcial', $this->financeiro, (string) Str::uuid());

        $this->postWebhook($this->event('refund.updated', ['id' => 're_meu', 'object' => 'refund', 'amount' => 1000, 'status' => 'succeeded', 'payment_intent' => $p->payment_intent_id, 'metadata' => ['local_refund' => (string) $r->id]]))->assertOk();
        $this->postWebhook($this->event('charge.refunded', ['id' => $p->charge_id, 'object' => 'charge', 'payment_intent' => $p->payment_intent_id,
            'refunds' => ['data' => [['id' => 're_meu', 'amount' => 1000, 'status' => 'succeeded'], ['id' => 're_painel', 'amount' => 2000, 'status' => 'succeeded']]]]))->assertOk();
        $this->postWebhook($this->event('refund.created', ['id' => 're_painel', 'object' => 'refund', 'amount' => 2000, 'status' => 'succeeded', 'charge' => $p->charge_id]))->assertOk();

        $this->assertSame(2, SubscriptionRefund::query()->count());
        $this->assertSame('succeeded', $r->refresh()->status);
        $this->assertSame(['stripe', 2000], [SubscriptionRefund::query()->where('gateway_refund_id', 're_painel')->value('source'), (int) SubscriptionRefund::query()->where('gateway_refund_id', 're_painel')->value('amount_cents')]);
        $this->assertSame(6900, $p->refresh()->refundableCents());
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status);
    }

    public function test_pagamento_manual_nao_e_reembolsado_pelo_sistema(): void
    {
        $s = Subscription::factory()->create(['customer_id' => $this->cliente->id, 'plan_id' => $this->clube->id]);
        $p = SubscriptionPayment::query()->create(['subscription_id' => $s->id, 'customer_id' => $this->cliente->id, 'gateway' => 'manual', 'amount_cents' => 9900, 'currency' => 'BRL', 'status' => 'paid', 'kind' => 'mensalidade', 'paid_at' => now()]);

        $this->expectException(SubscriptionRuleViolation::class);
        $this->manager()->refund($p, 100, 'Teste', $this->financeiro, (string) Str::uuid());
    }

    // --- Permissoes ------------------------------------------------------------------------------

    public function test_quem_acessa_o_que(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $p = SubscriptionPayment::query()->sole();
        $barbeiro = User::factory()->role(StaffRole::Professional)->create();
        $quem = ['dono' => $this->dono, 'gerente' => $this->gerente, 'recepcao' => $this->recepcao, 'financeiro' => $this->financeiro, 'barbeiro' => $barbeiro];
        $telas = [
            route('panel.subscriptions.index') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.subscriptions.show', $s) => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.subscriptions.link') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.subscriptions.events') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 403, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.plans.index') => ['dono' => 200, 'gerente' => 403, 'recepcao' => 403, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.plans.edit', $this->clube) => ['dono' => 200, 'gerente' => 403, 'recepcao' => 403, 'financeiro' => 403, 'barbeiro' => 403],
        ];
        foreach ($telas as $url => $esperado) {
            foreach ($esperado as $papel => $status) {
                $this->as($quem[$papel])->get($url)->assertStatus($status);
            }
        }

        // Pagamentos so com subscriptions.payments; acoes so com a habilidade da acao.
        $this->as($this->recepcao)->get(route('panel.subscriptions.show', $s))->assertDontSee('Reembolsar')->assertDontSee('Cancelar assinatura')->assertDontSee('R$ 99,00</td>', false);
        $this->as($this->financeiro)->get(route('panel.subscriptions.show', $s))->assertSee('Reembolsar')->assertDontSee('Cancelar assinatura');
        $this->as($this->recepcao)->post(route('panel.subscriptions.cancel', $s), ['mode' => 'end', 'reason' => 'x x x'])->assertForbidden();
        $this->as($this->financeiro)->post(route('panel.subscriptions.cancel', $s), ['mode' => 'end', 'reason' => 'x x x'])->assertForbidden();
        $this->as($this->gerente)->post(route('panel.subscriptions.refund', $p), ['request_key' => (string) Str::uuid(), 'amount' => '1,00', 'reason' => 'x x x'])->assertForbidden();
        $this->as($this->recepcao)->post(route('panel.subscriptions.reactivate', $s))->assertForbidden();
        $this->as($this->gerente)->post(route('panel.plans.store'), ['name' => 'X', 'price' => '10,00', 'services' => [$this->corte->id]])->assertForbidden();
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status);
        $this->assertSame(0, SubscriptionRefund::query()->count());

        // Cliente: so a propria; o painel nao.
        $outro = Customer::factory()->create();
        $this->flushSession();
        $this->actingAs($outro, 'customer')->get(route('account.subscription'))->assertOk()->assertSee('Você não tem assinatura')->assertDontSee('Clube do Corte');
        $this->flushSession();
        $this->actingAs($outro, 'customer')->post(route('account.subscription.cancel'))->assertNotFound();
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status);
    }
}

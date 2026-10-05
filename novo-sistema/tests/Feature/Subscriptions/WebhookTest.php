<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Gateway\StripeSignature;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Models\SubscriptionEvent;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\StripeWebhook;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Webhooks do Stripe (webhooks.md): assinatura do corpo, janela contra
 * replay, evento guardado uma vez, reentrega sem efeito duplo, processamento
 * transacional (falha nao aplica nada e o Stripe reenvia), reprocessamento
 * seguro e evento sem assinatura local.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
    }

    protected function tearDown(): void
    {
        StripeWebhook::$duringProcessing = null;
        parent::tearDown();
    }

    public function test_assinatura_invalida_ou_ausente_e_recusada_sem_gravar_nada(): void
    {
        $s = $this->startSignup($this->cliente);
        $evento = $this->event('invoice.paid', $this->invoice($s));

        $this->postWebhook($evento, 'whsec_outro_segredo')->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($evento))->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=123,v1=abc', 'CONTENT_TYPE' => 'application/json'], (string) json_encode($evento))->assertStatus(400);

        $this->assertSame([0, 0], [GatewayEvent::query()->count(), SubscriptionPayment::query()->count()]);
        $this->assertSame(SubscriptionStatus::Pending, $s->refresh()->status);
    }

    public function test_replay_fora_da_janela_e_recusado(): void
    {
        $s = $this->startSignup($this->cliente);
        $evento = $this->event('invoice.paid', $this->invoice($s));

        // Assinatura legitima, mas de 10 minutos atras (capturada e reenviada depois).
        $this->postWebhook($evento, null, now()->getTimestamp() - 600)->assertStatus(400);
        $this->assertSame(0, GatewayEvent::query()->count());
    }

    public function test_sem_segredo_configurado_tudo_e_recusado(): void
    {
        $s = $this->startSignup($this->cliente);
        config(['services.stripe.webhook_secret' => '']);

        $this->postWebhook($this->event('invoice.paid', $this->invoice($s)), '')->assertStatus(503);
        $this->assertSame(0, GatewayEvent::query()->count());
    }

    public function test_corpo_invalido_e_recusado(): void
    {
        $this->postWebhook(['id' => 'evt_x', 'type' => 'invoice.paid'])->assertStatus(400);
        $corpo = 'isto nao e json';
        $cab = StripeSignature::header($corpo, $this->webhookSecret, now()->getTimestamp());
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $cab], $corpo)->assertStatus(400);
        $this->assertSame(0, GatewayEvent::query()->count());
    }

    public function test_evento_guardado_uma_vez_e_reentrega_nao_duplica(): void
    {
        $s = $this->startSignup($this->cliente);
        $evento = $this->event('invoice.paid', $this->invoice($s));

        $this->postWebhook($evento)->assertOk()->assertJson(['result' => 'applied']);
        $this->postWebhook($evento)->assertOk()->assertJson(['result' => 'duplicate']);
        $this->postWebhook($evento, null, null, '/webhook_stripe.php')->assertOk()->assertJson(['result' => 'duplicate']);

        $e = GatewayEvent::query()->sole();
        $this->assertSame(['processed', 'applied', 1], [$e->status, $e->result, $e->attempts]);
        $this->assertSame((string) json_encode($evento), $e->payload, 'evento guardado inteiro para reprocessar');
        $this->assertSame(1, SubscriptionPayment::query()->count(), 'uma cobrança');
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'activated')->count(), 'uma ativação');
        $this->assertSame('2026-11-05', $s->refresh()->ends_on?->toDateString());
    }

    public function test_mesma_fatura_em_outro_evento_nao_duplica_pagamento_nem_beneficio(): void
    {
        $s = $this->startSignup($this->cliente);
        $fatura = $this->invoice($s);

        $this->postWebhook($this->event('invoice.paid', $fatura))->assertOk();
        $this->postWebhook($this->event('invoice.payment_succeeded', $fatura))->assertOk();

        $this->assertSame(1, SubscriptionPayment::query()->count());
        $this->assertSame('2026-11-05', $s->refresh()->ends_on?->toDateString());
        $this->assertSame(2, GatewayEvent::query()->count());
    }

    public function test_falha_no_meio_nao_aplica_nada_e_o_reprocessamento_aplica_uma_vez(): void
    {
        $s = $this->startSignup($this->cliente);
        $evento = $this->event('invoice.paid', $this->invoice($s));
        StripeWebhook::$duringProcessing = fn () => throw new RuntimeException('falha simulada');

        $this->postWebhook($evento)->assertStatus(500)->assertJsonMissing(['falha simulada']);

        $e = GatewayEvent::query()->sole();
        $this->assertSame(['failed', 1], [$e->status, $e->attempts]);
        $this->assertStringContainsString('falha simulada', (string) $e->last_error);
        $this->assertSame([0, SubscriptionStatus::Pending, null], [SubscriptionPayment::query()->count(), $s->refresh()->status, $s->ends_on], 'nada aplicado pela metade');

        StripeWebhook::$duringProcessing = null;
        $this->assertSame(0, Artisan::call('app:stripe-reprocess', ['--failed' => true]));
        $this->postWebhook($evento)->assertOk()->assertJson(['result' => 'duplicate']); // o reenvio do Stripe chega depois

        $this->assertSame([1, SubscriptionStatus::Active], [SubscriptionPayment::query()->count(), $s->refresh()->status]);
        $this->assertSame(['processed', 'applied'], [GatewayEvent::query()->sole()->status, GatewayEvent::query()->sole()->result]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_reentrega_do_stripe_apos_falha_aplica(): void
    {
        $s = $this->startSignup($this->cliente);
        $evento = $this->event('invoice.paid', $this->invoice($s));
        StripeWebhook::$duringProcessing = fn () => throw new RuntimeException('banco ocupado');
        $this->postWebhook($evento)->assertStatus(500);
        StripeWebhook::$duringProcessing = null;

        $this->postWebhook($evento)->assertOk()->assertJson(['result' => 'applied']);

        $this->assertSame(2, GatewayEvent::query()->sole()->attempts);
        $this->assertSame(1, SubscriptionPayment::query()->count());
    }

    public function test_evento_sem_assinatura_local_fica_guardado_e_e_aplicado_quando_o_vinculo_chega(): void
    {
        $s = $this->startSignup($this->cliente);
        // Fatura de outra versao da API, sem o metadado local: so o ID da assinatura do Stripe.
        $fatura = $this->invoice($s, extra: ['subscription_details' => null]);
        $this->postWebhook($this->event('invoice.paid', $fatura))->assertOk()->assertJson(['result' => 'unmatched']);
        $this->assertSame(SubscriptionStatus::Pending, $s->refresh()->status);

        // O checkout concluido liga a assinatura do Stripe a local: a fatura guardada e aplicada.
        $this->postWebhook($this->event('checkout.session.completed', [
            'id' => $s->checkout_session_id, 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid',
            'client_reference_id' => $s->public_id, 'customer' => 'cus_'.substr($s->public_id, 0, 8), 'subscription' => 'sub_'.substr($s->public_id, 0, 8),
        ]))->assertOk();

        $s->refresh();
        $this->assertSame([SubscriptionStatus::Active, '2026-11-05', 1], [$s->status, $s->ends_on?->toDateString(), SubscriptionPayment::query()->count()]);
        $this->assertSame(0, GatewayEvent::query()->where('result', 'unmatched')->count());
    }

    public function test_tipo_sem_efeito_e_guardado_e_ignorado(): void
    {
        $this->postWebhook($this->event('customer.updated', ['id' => 'cus_x', 'object' => 'customer']))->assertOk()->assertJson(['result' => 'ignored']);
        $this->assertSame('ignored', GatewayEvent::query()->sole()->result);
    }

    public function test_resposta_e_tela_nunca_mostram_a_chave(): void
    {
        $dono = User::factory()->owner()->create();
        $s = $this->activeSubscription($this->cliente);

        $html = $this->actingAs($dono)->get(route('panel.subscriptions.show', $s))->assertOk()->getContent()
            .$this->actingAs($dono)->get(route('panel.subscriptions.events'))->assertOk()->getContent();

        $this->assertStringNotContainsString((string) config('services.stripe.secret'), (string) $html);
        $this->assertStringNotContainsString($this->webhookSecret, (string) $html);
    }
}

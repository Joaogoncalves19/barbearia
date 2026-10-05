<?php

namespace Tests\Concerns;

use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Gateway\StripeSignature;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionCheckout;
use App\Modules\Subscriptions\Services\SubscriptionManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Assinaturas ficticias sobre o atendimento (PromotionFixtures: segunda
 * 05/10/2026 08:00; Corte R$ 50,00 e Barba R$ 30,00 com o Joao). Stripe
 * SIMULADO: chave e segredo do webhook gerados a cada teste (nunca fixos no
 * codigo) e nenhuma chamada real (Http::preventStrayRequests). Plano "Clube
 * do Corte": R$ 99,00/mes, inclui o Corte.
 */
trait SubscriptionFixtures
{
    use PromotionFixtures;

    protected Plan $clube;

    protected string $webhookSecret;

    private int $eventSeq = 0;

    protected function setUpSubscriptions(): void
    {
        $this->setUpPromotions();
        $this->webhookSecret = 'whsec_teste_'.Str::random(32);
        config([
            'services.stripe.secret' => 'sk_test_ficticio_'.Str::random(24),
            'services.stripe.webhook_secret' => $this->webhookSecret,
            'services.stripe.api_base' => 'https://stripe.teste',
        ]);
        Http::preventStrayRequests();
        $this->clube = Plan::factory()->withVersion(9900, [$this->corte->id])->create(['name' => 'Clube do Corte']);
    }

    protected function checkout(): SubscriptionCheckout
    {
        return app(SubscriptionCheckout::class);
    }

    protected function manager(): SubscriptionManager
    {
        return app(SubscriptionManager::class);
    }

    protected function ts(string $local = '2026-10-05 08:00'): int
    {
        return BusinessTime::at(substr($local, 0, 10), substr($local, 11, 5))->getTimestamp();
    }

    /**
     * Respostas simuladas do Stripe para as chamadas da API.
     *
     * @param  array<string, mixed>  $extra  padrao => resposta
     */
    protected function fakeStripe(array $extra = []): void
    {
        Http::fake([
            ...$extra,
            'stripe.teste/v1/checkout/sessions/*/expire' => Http::response(['id' => 'cs_x', 'status' => 'expired']),
            'stripe.teste/v1/checkout/sessions' => fn () => Http::response([
                'id' => 'cs_test_'.Str::random(10), 'url' => 'https://checkout.stripe.teste/pay/'.Str::random(8), 'expires_at' => $this->ts('2026-10-06 08:00'),
            ]),
        ]);
    }

    /** Adesao iniciada (link) para o cliente: assinatura local aguardando pagamento. */
    protected function startSignup(Customer $customer, ?Appointment $appointment = null, ?Plan $plan = null): Subscription
    {
        $this->fakeStripe();

        return $this->checkout()->start($customer, $plan ?? $this->clube, $appointment !== null ? SubscriptionOrigin::Booking : SubscriptionOrigin::Panel, $appointment, $appointment === null ? $this->recepcao : null);
    }

    /**
     * Evento do Stripe (como o Stripe envia).
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function event(string $type, array $object, ?int $created = null, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_teste_'.(++$this->eventSeq).'_'.Str::random(6),
            'object' => 'event',
            'type' => $type,
            'created' => $created ?? BusinessTime::now()->getTimestamp(),
            'livemode' => false,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function postWebhook(array $event, ?string $secret = null, ?int $timestamp = null, string $uri = '/webhooks/stripe'): TestResponse
    {
        $corpo = (string) json_encode($event);
        $cab = StripeSignature::header($corpo, $secret ?? $this->webhookSecret, $timestamp ?? BusinessTime::now()->getTimestamp());

        return $this->call('POST', $uri, [], [], [], ['HTTP_STRIPE_SIGNATURE' => $cab, 'CONTENT_TYPE' => 'application/json'], $corpo);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function subObject(Subscription $s, string $status = 'active', array $extra = [], ?int $periodEnd = null): array
    {
        return [
            'id' => $s->gateway_subscription_id ?? 'sub_'.substr($s->public_id, 0, 8),
            'object' => 'subscription',
            'customer' => $s->gateway_customer_id ?? 'cus_'.substr($s->public_id, 0, 8),
            'status' => $status,
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'current_period_end' => $periodEnd ?? $this->ts('2026-11-05 08:00'),
            'metadata' => ['local_subscription' => $s->public_id],
            ...$extra,
        ];
    }

    /**
     * Fatura paga (adesao ou renovacao).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function invoice(Subscription $s, string $start = '2026-10-05 08:00', string $end = '2026-11-05 08:00', string $reason = 'subscription_create', ?string $id = null, int $amount = 9900, array $extra = []): array
    {
        $sub = $s->gateway_subscription_id ?? 'sub_'.substr($s->public_id, 0, 8);

        return [
            'id' => $id ?? 'in_'.substr(md5($sub.$start), 0, 12),
            'object' => 'invoice',
            'subscription' => $sub,
            'customer' => $s->gateway_customer_id ?? 'cus_'.substr($s->public_id, 0, 8),
            'amount_paid' => $amount,
            'amount_due' => $amount,
            'currency' => 'brl',
            'billing_reason' => $reason,
            'payment_intent' => 'pi_'.substr(md5($sub.$start.'pi'), 0, 12),
            'charge' => 'ch_'.substr(md5($sub.$start.'ch'), 0, 12),
            'status_transitions' => ['paid_at' => $this->ts($start)],
            'subscription_details' => ['metadata' => ['local_subscription' => $s->public_id]],
            'lines' => ['data' => [['period' => ['start' => $this->ts($start), 'end' => $this->ts($end)]]]],
            ...$extra,
        ];
    }

    /** Adesao paga: link + checkout concluido + fatura paga (webhooks). */
    protected function activeSubscription(Customer $customer, ?Appointment $appointment = null): Subscription
    {
        $s = $this->startSignup($customer, $appointment);
        $this->postWebhook($this->event('checkout.session.completed', [
            'id' => $s->checkout_session_id, 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid',
            'client_reference_id' => $s->public_id, 'customer' => 'cus_'.substr($s->public_id, 0, 8), 'subscription' => 'sub_'.substr($s->public_id, 0, 8),
            'metadata' => ['local_subscription' => $s->public_id],
        ]))->assertOk();
        $this->postWebhook($this->event('customer.subscription.created', $this->subObject($s->refresh())))->assertOk();
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s->refresh())))->assertOk();

        return $s->refresh();
    }
}

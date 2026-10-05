<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\InvalidWebhook;
use App\Modules\Subscriptions\Gateway\StripeData;
use App\Modules\Subscriptions\Gateway\StripeSignature;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhooks do Stripe (webhooks.md). Em ordem:
 *
 * 1. Assinatura do Stripe conferida (HMAC + janela de 5 min: replay antigo
 *    recusado). Sem segredo configurado, tudo e recusado. Nada e gravado
 *    antes disso.
 * 2. O evento e guardado INTEIRO, uma vez (unico por gateway + ID). Reentrega
 *    do mesmo evento encontra o registro e nao processa de novo.
 * 3. Processamento em UMA transacao: a primeira escrita "reivindica" o evento
 *    (so se ainda nao processado), depois trava a assinatura, aplica e marca
 *    o evento como processado. Erro desfaz tudo, o evento fica "failed" com
 *    o erro e a resposta e 500: o Stripe reenvia; tambem da para reprocessar
 *    (app:stripe-reprocess). Reprocessar e seguro: cada efeito e idempotente.
 * 4. Ordem de chegada nao importa (SubscriptionLifecycle): fotografia mais
 *    antiga nao sobrescreve a mais nova; o direito so aumenta com fatura paga;
 *    fatura e reembolso sao unicos.
 *
 * Eventos usados (configure ESTES no endpoint do Stripe): checkout.session.completed,
 * checkout.session.async_payment_succeeded, checkout.session.expired,
 * customer.subscription.created/updated/deleted/paused/resumed, invoice.paid,
 * invoice.payment_succeeded, invoice.payment_failed, invoice.payment_action_required,
 * charge.refunded, refund.created/updated, charge.refund.updated. Outros: guardados e ignorados.
 */
final class StripeWebhook
{
    /** Gancho SO para testes: roda no meio do processamento (falha simulada). */
    public static ?Closure $duringProcessing = null;

    /** @var list<string> assinaturas do Stripe ligadas neste processamento */
    private array $ligadas = [];

    public function __construct(private readonly SubscriptionLifecycle $life) {}

    /**
     * @return array{event: GatewayEvent, result: string}
     *
     * @throws InvalidWebhook|Throwable
     */
    public function receive(string $payload, string $signatureHeader): array
    {
        StripeSignature::verify($payload, $signatureHeader, (string) config('services.stripe.webhook_secret'), (int) config('services.stripe.tolerance', 300), BusinessTime::now()->getTimestamp());

        $dados = json_decode($payload, true);
        if (! is_array($dados) || ! is_string($dados['id'] ?? null) || ! is_string($dados['type'] ?? null) || ! is_array(StripeData::get($dados, 'data.object'))) {
            throw new InvalidWebhook('bad_payload');
        }

        $evento = $this->store($dados, $payload);
        $resultado = $this->process($evento);

        foreach (array_unique($this->ligadas) as $sub) {
            $this->reprocessUnmatched($sub);
        }
        $this->ligadas = [];

        return ['event' => $evento->refresh(), 'result' => $resultado];
    }

    /**
     * Processa (ou reprocessa) um evento guardado. Idempotente.
     *
     * @throws Throwable
     */
    public function process(GatewayEvent $evento): string
    {
        if ($evento->status === 'processed') {
            return 'duplicate';
        }

        try {
            return DB::transaction(function () use ($evento): string {
                // Reivindica o evento (primeira escrita): outra entrega do mesmo evento espera ou desiste.
                $livre = GatewayEvent::query()->whereKey($evento->id)->where('status', '<>', 'processed')->increment('attempts');
                if ($livre === 0) {
                    return 'duplicate';
                }
                $dados = $evento->payloadArray();
                [$resultado, $assinatura] = $this->dispatch($evento, $dados);
                if (self::$duringProcessing !== null) {
                    (self::$duringProcessing)($evento);
                }
                GatewayEvent::query()->whereKey($evento->id)->update([
                    'status' => 'processed', 'result' => $resultado, 'processed_at' => BusinessTime::now(), 'last_error' => null, 'subscription_id' => $assinatura,
                ]);

                return $resultado;
            });
        } catch (Throwable $e) {
            GatewayEvent::query()->whereKey($evento->id)->where('status', '<>', 'processed')->update([
                'status' => 'failed', 'attempts' => DB::raw('attempts + 1'), 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000),
            ]);
            Log::error('Webhook do Stripe falhou (será reenviado)', ['evento' => $evento->event_id, 'tipo' => $evento->type, 'erro' => class_basename($e)]);
            throw $e;
        }
    }

    /**
     * Eventos guardados sem assinatura local (chegaram antes do vinculo):
     * reprocessa os que citam a assinatura do Stripe agora vinculada.
     */
    public function reprocessUnmatched(string $gatewaySubscriptionId): int
    {
        $n = 0;
        $pendentes = GatewayEvent::query()->where('gateway', 'stripe')->where('result', 'unmatched')
            ->where('payload', 'like', '%'.str_replace('%', '', $gatewaySubscriptionId).'%')->orderBy('event_created_at')->orderBy('id')->get();
        foreach ($pendentes as $e) {
            GatewayEvent::query()->whereKey($e->id)->update(['status' => 'received', 'result' => null]);
            try {
                $this->process($e->refresh());
                $n++;
            } catch (Throwable) {
                // fica "failed" para o reenvio do Stripe ou o reprocessamento manual
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function store(array $dados, string $payload): GatewayEvent
    {
        $existente = GatewayEvent::query()->where('gateway', 'stripe')->where('event_id', $dados['id'])->first();
        if ($existente !== null) {
            return $existente;
        }
        $obj = (array) StripeData::get($dados, 'data.object');
        try {
            return GatewayEvent::query()->create([
                'gateway' => 'stripe',
                'event_id' => mb_substr((string) $dados['id'], 0, 255),
                'type' => mb_substr((string) $dados['type'], 0, 255),
                'status' => 'received',
                'object_type' => is_string($obj['object'] ?? null) ? mb_substr($obj['object'], 0, 32) : null,
                'object_id' => StripeData::id($obj['id'] ?? null),
                'event_created_at' => is_numeric($dados['created'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $dados['created']) : null,
                'livemode' => (bool) ($dados['livemode'] ?? false),
                'payload' => $payload,
                'received_at' => BusinessTime::now(),
            ]);
        } catch (QueryException $e) {
            // Duas entregas do mesmo evento ao mesmo tempo: o indice unico barra a segunda.
            return GatewayEvent::query()->where('gateway', 'stripe')->where('event_id', $dados['id'])->first() ?? throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{0: string, 1: ?int}
     */
    private function dispatch(GatewayEvent $evento, array $dados): array
    {
        /** @var array<string, mixed> $obj */
        $obj = (array) StripeData::get($dados, 'data.object');
        $quando = $evento->event_created_at !== null ? CarbonImmutable::parse($evento->event_created_at) : BusinessTime::now();

        return match ((string) $evento->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->checkoutCompleted($obj, $evento),
            'checkout.session.expired' => $this->checkoutExpired($obj, $evento),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
            'customer.subscription.paused', 'customer.subscription.resumed' => $this->subscriptionSnapshot($obj, $quando, $evento),
            'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($obj, $quando, $evento),
            'invoice.payment_failed' => $this->invoiceProblem($obj, $evento, SubscriptionEventKind::PaymentFailed),
            'invoice.payment_action_required' => $this->invoiceProblem($obj, $evento, SubscriptionEventKind::PaymentPending),
            'charge.refunded' => $this->chargeRefunded($obj, $quando),
            'refund.created', 'refund.updated', 'charge.refund.updated' => $this->refund($obj, $quando),
            default => ['ignored', null],
        };
    }

    /**
     * @param  array<string, mixed>  $obj
     * @return array{0: string, 1: ?int}
     */
    private function checkoutCompleted(array $obj, GatewayEvent $evento): array
    {
        if (($obj['mode'] ?? null) !== 'subscription') {
            return ['ignored', null];
        }
        $s = $this->find(StripeData::localId($obj), StripeData::id($obj['subscription'] ?? null), StripeData::id($obj['id'] ?? null));
        if ($s === null) {
            return ['unmatched', null];
        }
        $s = $this->life->lock($s->id);
        $sub = StripeData::id($obj['subscription'] ?? null);
        if ($sub !== null && $s->gateway_subscription_id !== null && $s->gateway_subscription_id !== $sub) {
            Log::warning('Stripe: checkout de outra assinatura', ['assinatura' => $s->id]);

            return ['unmatched', $s->id];
        }
        $s->gateway_customer_id ??= StripeData::id($obj['customer'] ?? null);
        $s->gateway_subscription_id ??= $sub;
        $s->save();
        SubscriptionHistory::record($s, SubscriptionEventKind::StatusSynced, EventSource::Stripe, $s->status, $s->status, null,
            'Pagamento concluído no Stripe ('.((string) ($obj['payment_status'] ?? '')).').', null, $evento->id);
        if ($sub !== null) {
            $this->ligadas[] = $sub;
        }

        return ['applied', $s->id];
    }

    /**
     * @param  array<string, mixed>  $obj
     * @return array{0: string, 1: ?int}
     */
    private function checkoutExpired(array $obj, GatewayEvent $evento): array
    {
        $s = $this->find(StripeData::localId($obj), null, StripeData::id($obj['id'] ?? null));
        if ($s === null) {
            return ['unmatched', null];
        }
        $s = $this->life->lock($s->id);
        if ($s->status !== SubscriptionStatus::Pending || $s->checkout_session_id !== StripeData::id($obj['id'] ?? null)) {
            return ['ignored', $s->id];
        }
        $this->life->transition($s, SubscriptionStatus::Expired, SubscriptionEventKind::Expired, EventSource::Stripe, null, 'Link de pagamento expirou sem pagamento.', $evento->id);

        return ['applied', $s->id];
    }

    /**
     * @param  array<string, mixed>  $obj
     * @return array{0: string, 1: ?int}
     */
    private function subscriptionSnapshot(array $obj, CarbonImmutable $quando, GatewayEvent $evento): array
    {
        $s = $this->find(StripeData::localId($obj), StripeData::id($obj['id'] ?? null), null);
        if ($s === null) {
            return ['unmatched', null];
        }
        $s = $this->life->lock($s->id);
        if ($s->gateway_subscription_id !== null && $s->gateway_subscription_id !== StripeData::id($obj['id'] ?? null)) {
            return ['unmatched', $s->id];
        }

        return [$this->life->applyStripeSnapshot($s, $obj, $quando, EventSource::Stripe, null, $evento->id), $s->id];
    }

    /**
     * @param  array<string, mixed>  $obj
     * @return array{0: string, 1: ?int}
     */
    private function invoicePaid(array $obj, CarbonImmutable $quando, GatewayEvent $evento): array
    {
        $sub = StripeData::invoiceSubscription($obj);
        $s = $this->find(StripeData::localId($obj), $sub, null);
        if ($s === null) {
            return ['unmatched', null];
        }
        $s = $this->life->lock($s->id);
        if ($sub !== null && $s->gateway_subscription_id === null) {
            $s->gateway_subscription_id = $sub;
            $s->gateway_customer_id ??= StripeData::id($obj['customer'] ?? null);
        }
        $r = $this->life->recordPaidInvoice($s, $obj, $quando, $evento->id);

        return [$r === 'duplicate' ? 'applied' : $r, $s->id];
    }

    /**
     * Falha ou pendencia de pagamento: so historico. O estado (em atraso) vem
     * da fotografia da assinatura; o direito so acaba na data paga.
     *
     * @param  array<string, mixed>  $obj
     * @return array{0: string, 1: ?int}
     */
    private function invoiceProblem(array $obj, GatewayEvent $evento, SubscriptionEventKind $kind): array
    {
        $s = $this->find(StripeData::localId($obj), StripeData::invoiceSubscription($obj), null);
        if ($s === null) {
            return ['unmatched', null];
        }
        $s = $this->life->lock($s->id);
        $proxima = StripeData::get($obj, 'next_payment_attempt');
        SubscriptionHistory::record($s, $kind, EventSource::Stripe, $s->status, $s->status, null, null, null, $evento->id, [
            'fatura' => StripeData::id($obj['id'] ?? null),
            'valor_cents' => (int) ($obj['amount_due'] ?? 0),
            'tentativa' => (int) ($obj['attempt_count'] ?? 0),
            'proxima_tentativa' => is_numeric($proxima) ? CarbonImmutable::createFromTimestamp((int) $proxima)->toIso8601String() : null,
        ]);

        return ['applied', $s->id];
    }

    /**
     * @param  array<string, mixed>  $charge
     * @return array{0: string, 1: ?int}
     */
    private function chargeRefunded(array $charge, CarbonImmutable $quando): array
    {
        $pagamento = $this->payment(StripeData::id($charge['payment_intent'] ?? null), StripeData::id($charge['id'] ?? null));
        if ($pagamento === null) {
            return ['unmatched', null];
        }
        $this->lockFor($pagamento);
        $resultado = 'ignored';
        foreach ((array) StripeData::get($charge, 'refunds.data') as $r) {
            if (is_array($r)) {
                $resultado = $this->life->recordStripeRefund($pagamento, $r, $quando) === 'applied' ? 'applied' : $resultado;
            }
        }

        return [$resultado, $pagamento->subscription_id];
    }

    /**
     * @param  array<string, mixed>  $refund
     * @return array{0: string, 1: ?int}
     */
    private function refund(array $refund, CarbonImmutable $quando): array
    {
        $pagamento = $this->payment(StripeData::id($refund['payment_intent'] ?? null), StripeData::id($refund['charge'] ?? null));
        if ($pagamento === null) {
            return ['unmatched', null];
        }
        $this->lockFor($pagamento);
        $r = $this->life->recordStripeRefund($pagamento, $refund, $quando);

        return [$r === 'duplicate' ? 'applied' : $r, $pagamento->subscription_id];
    }

    private function find(?string $publicId, ?string $gatewaySubscriptionId, ?string $checkoutSessionId): ?Subscription
    {
        if ($gatewaySubscriptionId !== null && ($s = Subscription::query()->where('gateway_subscription_id', $gatewaySubscriptionId)->first()) !== null) {
            return $s;
        }
        if ($publicId !== null && ($s = Subscription::query()->where('public_id', $publicId)->first()) !== null) {
            return $s;
        }
        if ($checkoutSessionId !== null) {
            return Subscription::query()->where('checkout_session_id', $checkoutSessionId)->first();
        }

        return null;
    }

    private function payment(?string $paymentIntent, ?string $charge): ?SubscriptionPayment
    {
        if ($paymentIntent !== null && ($p = SubscriptionPayment::query()->where('payment_intent_id', $paymentIntent)->first()) !== null) {
            return $p;
        }

        return $charge !== null ? SubscriptionPayment::query()->where('charge_id', $charge)->first() : null;
    }

    private function lockFor(SubscriptionPayment $p): void
    {
        if ($p->subscription_id !== null) {
            $this->life->lock($p->subscription_id);
        }
    }
}

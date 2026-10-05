<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Gateway\StripeClient;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanVersion;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\System\Services\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Adesao (decisao do dono D-47): como no sistema antigo, o cliente assina
 * NO AGENDAMENTO, pagando no Stripe Checkout; a equipe tambem pode gerar um
 * LINK de pagamento no painel para o cliente pagar. Nos dois casos a
 * assinatura so ativa quando o Stripe confirma o pagamento (webhook).
 *
 * 1. Grava a assinatura local "aguardando pagamento" (uma vigente por
 *    cliente: a sentinela do banco barra a segunda) com a VERSAO do plano.
 * 2. Cria a sessao no Stripe, fora da transacao, com chave de idempotencia
 *    (a mesma assinatura local nunca abre duas cobrancas): preco da versao,
 *    mensal, identificador publico local como metadado e referencia.
 * 3. Guarda a sessao (link e validade). Se o Stripe falhar, a assinatura
 *    local vira "expirada" (nada fica aguardando para sempre).
 *
 * Um link anterior ainda aberto do mesmo cliente e expirado antes (no Stripe
 * e aqui), para nunca existirem dois links pagaveis.
 */
final class SubscriptionCheckout
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SubscriptionLifecycle $life,
    ) {}

    public function available(): bool
    {
        return $this->stripe->isConfigured();
    }

    /**
     * @throws SubscriptionRuleViolation
     */
    public function start(Customer $customer, Plan $plan, SubscriptionOrigin $origin, ?Appointment $appointment, ?User $actor): Subscription
    {
        if (! $this->available()) {
            throw new SubscriptionRuleViolation('stripe_not_configured');
        }
        if (! $plan->is_active || $plan->trashed()) {
            throw new SubscriptionRuleViolation('plan_unavailable');
        }
        $versao = PlanVersion::query()->where('current_plan_id', $plan->id)->first();
        if ($versao === null) {
            throw new SubscriptionRuleViolation('plan_unavailable');
        }
        if ($customer->email === null || trim((string) $customer->email) === '') {
            throw new SubscriptionRuleViolation('customer_required');
        }

        $vigente = Subscription::query()->where('customer_id', $customer->id)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::CancelScheduled->value])->exists();
        if ($vigente) {
            throw new SubscriptionRuleViolation('already_subscribed');
        }

        $this->expireOpenLink($customer, $actor);

        // 1) Assinatura local aguardando pagamento.
        try {
            $s = DB::transaction(function () use ($customer, $plan, $versao, $origin, $appointment, $actor): Subscription {
                $anterior = Subscription::query()->where('customer_id', $customer->id)->whereNotNull('gateway_customer_id')->latest('id')->first();
                $s = Subscription::query()->create([
                    'customer_id' => $customer->id,
                    'plan_id' => $plan->id,
                    'plan_version_id' => $versao->id,
                    'status' => SubscriptionStatus::Pending,
                    'origin' => $origin,
                    'gateway' => Gateway::Stripe,
                    'gateway_customer_id' => $anterior?->gateway_customer_id,
                    'signup_appointment_id' => $appointment?->id,
                    'created_by_user_id' => $actor?->id,
                ]);
                SubscriptionHistory::record($s, SubscriptionEventKind::Created, $actor !== null ? EventSource::Staff : EventSource::Customer, null, SubscriptionStatus::Pending,
                    $actor ?? $customer, null, null, null, ['plano' => $plan->name, 'versao' => $versao->version, 'preco_cents' => $versao->price_cents, 'origem' => $origin->value]);

                return $s;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new SubscriptionRuleViolation('already_subscribed');
            }
            throw $e;
        }

        // 2) Sessao no Stripe (fora da transacao; idempotente pela assinatura local).
        try {
            $sessao = $this->stripe->createCheckoutSession($this->params($s, $customer, $plan, $versao, $origin), 'checkout-'.$s->public_id);
        } catch (SubscriptionRuleViolation $e) {
            DB::transaction(function () use ($s): void {
                $t = $this->life->lock($s->id);
                $this->life->transition($t, SubscriptionStatus::Expired, SubscriptionEventKind::Expired, EventSource::System, null, 'Pagamento não iniciado: o Stripe não criou a sessão.');
            });
            throw $e;
        }

        // 3) Guarda o link.
        return DB::transaction(function () use ($s, $sessao, $actor, $customer, $plan): Subscription {
            $t = $this->life->lock($s->id);
            $t->forceFill([
                'checkout_session_id' => is_string($sessao['id'] ?? null) ? $sessao['id'] : null,
                'checkout_url' => is_string($sessao['url'] ?? null) ? $sessao['url'] : null,
                'checkout_expires_at' => is_numeric($sessao['expires_at'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $sessao['expires_at']) : BusinessTime::now()->addDay(),
            ])->save();
            SubscriptionHistory::record($t, SubscriptionEventKind::CheckoutStarted, $actor !== null ? EventSource::Staff : EventSource::Customer, $t->status, $t->status, $actor ?? $customer);
            if ($actor !== null) {
                AuditTrail::record('subscription.link_created', $t, $actor, 'Link de pagamento da assinatura ('.$plan->name.') gerado para '.$customer->name.'.', ['plano' => $plan->name]);
            }

            return $t;
        });
    }

    /**
     * Link ainda aberto do cliente: expira no Stripe e aqui (nunca dois pagaveis).
     *
     * @throws SubscriptionRuleViolation
     */
    private function expireOpenLink(Customer $customer, ?User $actor): void
    {
        $aberta = Subscription::query()->where('customer_id', $customer->id)->where('status', SubscriptionStatus::Pending->value)->first();
        if ($aberta === null) {
            return;
        }
        if ($aberta->checkout_session_id !== null) {
            $this->stripe->expireCheckoutSession($aberta->checkout_session_id, 'expire-'.$aberta->public_id);
        }
        DB::transaction(function () use ($aberta, $actor): void {
            $t = $this->life->lock($aberta->id);
            if ($t->status === SubscriptionStatus::Pending) {
                $this->life->transition($t, SubscriptionStatus::Expired, SubscriptionEventKind::Expired, $actor !== null ? EventSource::Staff : EventSource::Customer, $actor,
                    'Substituído por um novo link de pagamento.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Subscription $s, Customer $customer, Plan $plan, PlanVersion $versao, SubscriptionOrigin $origin): array
    {
        $meta = ['local_subscription' => $s->public_id, 'origem' => $origin->value];
        $p = [
            'mode' => 'subscription',
            'locale' => 'pt-BR',
            'payment_method_types' => ['card'],
            'client_reference_id' => $s->public_id,
            'metadata' => $meta,
            'subscription_data' => ['metadata' => $meta],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => (string) config('services.stripe.currency', 'brl'),
                    'unit_amount' => $versao->price_cents,
                    'recurring' => ['interval' => $versao->interval],
                    'product_data' => ['name' => 'Assinatura: '.$plan->name],
                ],
            ]],
            // URLs pela configuracao (APP_URL), nunca pelo cabecalho Host (S-19).
            'success_url' => route('account.subscription', ['pagamento' => 'ok']),
            'cancel_url' => route('account.subscription', ['pagamento' => 'cancelado']),
        ];
        if ($s->gateway_customer_id !== null) {
            $p['customer'] = $s->gateway_customer_id;
        } else {
            $p['customer_email'] = (string) $customer->email;
        }

        return $p;
    }
}

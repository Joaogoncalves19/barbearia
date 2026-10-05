<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Services\PromotionService;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Scheduling\Services\AppointmentPricing;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Gateway\StripeData;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionRefund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * A UNICA consolidacao do estado da assinatura (assinaturas.md §3,
 * webhooks.md §4). Chamada pelo processador de webhooks e pelas acoes da
 * equipe/cliente, sempre DENTRO da transacao do chamador e com a assinatura
 * travada (lock()).
 *
 * Regras de consolidacao (nao confiar na ordem de chegada):
 * - Fotografia da assinatura do Stripe (customer.subscription.*, ou a
 *   resposta de uma chamada a API) so e aplicada se for MAIS NOVA que a
 *   ultima aplicada (gateway_synced_at); a mais antiga e registrada como
 *   "stale" e nao muda nada.
 * - O direito ao beneficio (ends_on) so AUMENTA com pagamento confirmado
 *   (fatura paga): max(atual, fim do periodo pago). Fatura repetida ou fora
 *   de ordem nao encurta nada. So o cancelamento IMEDIATO encurta.
 * - Pagamento (um por fatura) e reembolso (um por ID do Stripe) sao unicos
 *   no banco: o mesmo evento duas vezes nao grava duas vezes.
 * - Transicao invalida (ex.: cancelada voltando a ativa) nao e aplicada:
 *   fica no historico e no log para conferencia.
 */
final class SubscriptionLifecycle
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly AppointmentPricing $appointmentPricing,
    ) {}

    /** Trava da assinatura: primeira escrita da transacao. */
    public function lock(int $subscriptionId): Subscription
    {
        Subscription::query()->whereKey($subscriptionId)->increment('version');

        return Subscription::query()->findOrFail($subscriptionId);
    }

    /**
     * Muda o estado (so transicoes permitidas) e registra no historico.
     *
     * @param  array<string, mixed>  $data
     */
    public function transition(Subscription $s, SubscriptionStatus $to, SubscriptionEventKind $kind, EventSource $source, User|Customer|null $actor = null, ?string $reason = null, ?int $gatewayEventId = null, array $data = []): bool
    {
        $de = $s->status;
        if ($de !== null && ! $de->canTransitionTo($to)) {
            Log::warning('Assinatura: transição recusada', ['assinatura' => $s->id, 'de' => $de->value, 'para' => $to->value]);
            SubscriptionHistory::record($s, SubscriptionEventKind::StatusSynced, $source, $de, $de, $actor,
                'Mudança para "'.$to->label().'" não aplicada (transição não permitida).', null, $gatewayEventId, $data);

            return false;
        }
        $s->status = $to;
        $s->save();
        SubscriptionHistory::record($s, $kind, $source, $de, $to, $actor, $reason, null, $gatewayEventId, $data);

        return true;
    }

    /**
     * Fotografia da assinatura no Stripe (evento ou resposta da API).
     *
     * @param  array<string, mixed>  $obj
     * @return 'applied'|'stale'
     */
    public function applyStripeSnapshot(Subscription $s, array $obj, CarbonImmutable $at, EventSource $source, User|Customer|null $actor = null, ?int $gatewayEventId = null, ?string $reason = null): string
    {
        if ($s->gateway_synced_at !== null && $at->lt($s->gateway_synced_at)) {
            return 'stale';
        }

        $statusStripe = (string) ($obj['status'] ?? '');
        $cancelAt = is_numeric($obj['cancel_at'] ?? null) ? (int) $obj['cancel_at'] : null;
        $agendado = (bool) ($obj['cancel_at_period_end'] ?? false) || ($cancelAt !== null && $cancelAt > $at->getTimestamp());
        $fimPeriodo = StripeData::periodEnd($obj);

        $s->gateway_status = $statusStripe !== '' ? mb_substr($statusStripe, 0, 32) : $s->gateway_status;
        $s->cancel_at_period_end = $agendado;
        $s->gateway_period_end_at = $fimPeriodo !== null ? CarbonImmutable::createFromTimestamp($fimPeriodo) : $s->gateway_period_end_at;
        $s->gateway_customer_id ??= StripeData::id($obj['customer'] ?? null);
        $s->gateway_subscription_id ??= StripeData::id($obj['id'] ?? null);
        $s->gateway_synced_at = $at;

        $para = self::localStatus($statusStripe, $agendado);
        $antes = $s->status;
        if ($para === null || $para === $antes) {
            $s->save();

            return 'applied';
        }

        // Dados do cancelamento, antes da transicao (vao junto no save).
        if ($para === SubscriptionStatus::CancelScheduled) {
            $s->cancel_requested_at ??= $at;
            $s->cancel_source ??= $source;
        }
        if ($para === SubscriptionStatus::Cancelled) {
            $fim = $obj['ended_at'] ?? $obj['canceled_at'] ?? null;
            $efetivo = is_numeric($fim) ? CarbonImmutable::createFromTimestamp((int) $fim) : $at;
            $s->cancelled_at ??= $efetivo;
            $s->cancel_source ??= $source;
            $s->cancel_effective_on = CarbonImmutable::parse(BusinessTime::dateOf($efetivo));
            // Encerrada ANTES do fim pago (cancelamento imediato): o direito
            // termina no dia anterior. No fim do periodo, nada muda.
            if ($s->ends_on !== null && BusinessTime::dateOf($efetivo) < $s->ends_on->toDateString()) {
                $s->ends_on = CarbonImmutable::parse(BusinessTime::dateOf($efetivo))->subDay();
            }
        }
        if ($antes === SubscriptionStatus::CancelScheduled && $para === SubscriptionStatus::Active) {
            $s->cancel_requested_at = null;
            $s->cancel_source = null;
            $s->cancel_reason = null;
            $s->cancelled_by_user_id = null;
        }

        $tipo = match (true) {
            $para === SubscriptionStatus::CancelScheduled => SubscriptionEventKind::CancelScheduled,
            $para === SubscriptionStatus::Cancelled => SubscriptionEventKind::Cancelled,
            $para === SubscriptionStatus::Expired => SubscriptionEventKind::Expired,
            $antes === SubscriptionStatus::CancelScheduled && $para === SubscriptionStatus::Active => SubscriptionEventKind::Reactivated,
            $antes === SubscriptionStatus::PastDue && $para === SubscriptionStatus::Active => SubscriptionEventKind::Recovered,
            default => SubscriptionEventKind::StatusSynced,
        };
        $this->transition($s, $para, $tipo, $source, $actor, $reason, $gatewayEventId, ['stripe_status' => $statusStripe]);

        return 'applied';
    }

    /**
     * Fatura paga: registra o pagamento (uma vez) e estende o direito.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function recordPaidInvoice(Subscription $s, array $invoice, CarbonImmutable $at, ?int $gatewayEventId = null): string
    {
        $faturaId = StripeData::id($invoice['id'] ?? null);
        $valor = (int) ($invoice['amount_paid'] ?? 0);
        [$ini, $fim] = StripeData::invoicePeriod($invoice);
        $pagoEm = is_numeric(StripeData::get($invoice, 'status_transitions.paid_at')) ? CarbonImmutable::createFromTimestamp((int) StripeData::get($invoice, 'status_transitions.paid_at')) : $at;
        $motivo = (string) ($invoice['billing_reason'] ?? '');

        $novo = false;
        if ($faturaId !== null && $valor > 0 && ! SubscriptionPayment::query()->where('gateway_payment_id', $faturaId)->exists()) {
            SubscriptionPayment::query()->create([
                'subscription_id' => $s->id,
                'customer_id' => $s->customer_id,
                'plan_id' => $s->plan_id,
                'plan_version_id' => $s->plan_version_id,
                'gateway' => 'stripe',
                'gateway_payment_id' => $faturaId,
                'gateway_subscription_id' => StripeData::invoiceSubscription($invoice) ?? $s->gateway_subscription_id,
                'payment_intent_id' => StripeData::invoicePaymentIntent($invoice),
                'charge_id' => StripeData::invoiceCharge($invoice),
                'amount_cents' => $valor,
                'currency' => strtoupper((string) ($invoice['currency'] ?? 'brl')),
                'status' => 'paid',
                'kind' => match ($motivo) {
                    'subscription_create' => 'signup', 'subscription_cycle' => 'renewal', default => 'other'
                },
                'period_start' => $ini !== null ? BusinessTime::dateOf(CarbonImmutable::createFromTimestamp($ini)) : null,
                'period_end' => $fim !== null ? BusinessTime::dateOf(CarbonImmutable::createFromTimestamp($fim)) : null,
                'paid_at' => $pagoEm,
                'created_at' => BusinessTime::now(),
            ]);
            $novo = true;
        }

        // Direito: so aumenta. Sem periodo na fatura: ciclo de 30 dias (R-26).
        $antesFim = $s->ends_on?->toDateString();
        $pagoAte = $fim !== null
            ? BusinessTime::dateOf(CarbonImmutable::createFromTimestamp($fim))
            : CarbonImmutable::parse(max(BusinessTime::today(), $antesFim ?? BusinessTime::today()))->addDays(30)->toDateString();
        if ($s->status !== null && ! $s->status->isFinal() && ($antesFim === null || $pagoAte > $antesFim)) {
            $s->ends_on = CarbonImmutable::parse($pagoAte);
        }
        if ($s->starts_on === null && $ini !== null) {
            $s->starts_on = CarbonImmutable::parse(BusinessTime::dateOf(CarbonImmutable::createFromTimestamp($ini)));
        }
        if ($faturaId !== null && $novo) {
            $s->last_gateway_payment_id = $faturaId;
        }
        $primeira = $s->activated_at === null && $s->status !== null && ! $s->status->isFinal();
        if ($primeira) {
            $s->activated_at = $at;
        }

        $dados = ['fatura' => $faturaId, 'valor_cents' => $valor, 'pago_ate' => $s->ends_on?->toDateString()];
        if ($s->status === SubscriptionStatus::Pending || $s->status === SubscriptionStatus::PastDue) {
            $tipo = $s->status === SubscriptionStatus::Pending ? SubscriptionEventKind::Activated : SubscriptionEventKind::Recovered;
            $this->transition($s, SubscriptionStatus::Active, $tipo, EventSource::Stripe, null, null, $gatewayEventId, $dados);
        } else {
            $s->save();
            if ($novo || $pagoAte !== $antesFim) {
                SubscriptionHistory::record($s, $primeira ? SubscriptionEventKind::Activated : SubscriptionEventKind::Renewed, EventSource::Stripe, $s->status, $s->status, null, null, null, $gatewayEventId, $dados);
            }
        }

        if ($primeira) {
            $this->applyBenefitToSignupAppointment($s, $gatewayEventId);
        }

        return $novo ? 'applied' : 'duplicate';
    }

    /**
     * Reembolso conhecido pelo Stripe (feito aqui ou no painel do Stripe):
     * grava uma vez por ID; o mesmo reembolso de novo so atualiza a situacao.
     *
     * @param  array<string, mixed>  $refund
     */
    public function recordStripeRefund(SubscriptionPayment $payment, array $refund, CarbonImmutable $at): string
    {
        $id = StripeData::id($refund['id'] ?? null);
        if ($id === null) {
            return 'ignored';
        }
        $status = match ((string) ($refund['status'] ?? 'succeeded')) {
            'succeeded' => 'succeeded', 'pending', 'requires_action' => 'pending', default => 'failed',
        };
        $existente = SubscriptionRefund::query()->where('gateway_refund_id', $id)->first();
        // Reembolso pedido aqui: o webhook pode chegar antes da resposta da API.
        $local = StripeData::get($refund, 'metadata.local_refund');
        if ($existente === null && is_numeric($local)) {
            $existente = SubscriptionRefund::query()->whereKey((int) $local)->where('subscription_payment_id', $payment->id)->whereNull('gateway_refund_id')->first();
            $existente?->forceFill(['gateway_refund_id' => $id])->save();
        }
        if ($existente !== null) {
            if ($existente->status !== $status) {
                $existente->forceFill(['status' => $status, 'refunded_at' => $status === 'succeeded' ? ($existente->refunded_at ?? $at) : $existente->refunded_at])->save();
            }

            return 'duplicate';
        }
        $valor = min((int) ($refund['amount'] ?? 0), $payment->amount_cents);
        $r = SubscriptionRefund::query()->create([
            'subscription_payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'customer_id' => $payment->customer_id,
            'amount_cents' => $valor,
            'status' => $status,
            'reason' => 'Reembolso registrado pelo Stripe',
            'source' => 'stripe',
            'gateway_refund_id' => $id,
            'refunded_at' => $status === 'succeeded' ? $at : null,
            'created_at' => BusinessTime::now(),
        ]);
        if ($payment->subscription_id !== null && ($s = Subscription::query()->find($payment->subscription_id)) !== null) {
            SubscriptionHistory::record($s, SubscriptionEventKind::Refunded, EventSource::Stripe, $s->status, $s->status, null, 'Reembolso feito no Stripe',
                null, null, ['reembolso' => $r->id, 'valor_cents' => $valor, 'pagamento' => $payment->gateway_payment_id]);
        }

        return 'applied';
    }

    /**
     * Adesao feita no agendamento: quando o pagamento confirma, o beneficio
     * entra no agendamento de origem (se ainda nao foi atendido nem cancelado
     * e a data esta coberta). Vale o maior.
     */
    public function applyBenefitToSignupAppointment(Subscription $s, ?int $gatewayEventId = null): void
    {
        $ag = $s->signup_appointment_id !== null ? Appointment::query()->find($s->signup_appointment_id) : null;
        if ($ag === null || ! in_array($ag->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)
            || Attendance::query()->where('appointment_id', $ag->id)->exists()) {
            return;
        }
        $c = $this->promotions->reapplyToAppointment($ag);
        if ($c === null) {
            return;
        }
        $this->appointmentPricing->refresh($ag);
        AppointmentEvent::query()->create([
            'appointment_id' => $ag->id,
            'type' => 'adjusted',
            'actor_label' => 'Sistema (assinatura)',
            'description' => $c->label.' aplicado: '.Money::fromCents($c->amountCents)->format().' de desconto (assinatura confirmada).',
            'data' => ['desconto_cents' => $c->amountCents, 'total_cents' => $ag->total_cents],
            'occurred_at' => BusinessTime::now(),
        ]);
        SubscriptionHistory::record($s, SubscriptionEventKind::BenefitApplied, EventSource::System, $s->status, $s->status, null, null, null, $gatewayEventId,
            ['agendamento' => $ag->code, 'desconto_cents' => $c->amountCents]);
    }

    /** Estado local a partir do estado do Stripe (ver assinaturas.md §3). */
    public static function localStatus(string $stripeStatus, bool $cancelScheduled): ?SubscriptionStatus
    {
        return match ($stripeStatus) {
            'incomplete' => SubscriptionStatus::Pending,
            'incomplete_expired' => SubscriptionStatus::Expired,
            'active', 'trialing' => $cancelScheduled ? SubscriptionStatus::CancelScheduled : SubscriptionStatus::Active,
            'past_due', 'unpaid', 'paused' => SubscriptionStatus::PastDue,
            'canceled' => SubscriptionStatus::Cancelled,
            default => null,
        };
    }
}

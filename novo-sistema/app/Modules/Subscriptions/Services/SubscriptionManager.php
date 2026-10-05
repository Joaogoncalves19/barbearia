<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Gateway\StripeClient;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionRefund;
use App\Modules\System\Services\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Acoes sobre a assinatura (cancelamentos.md, reembolsos.md): cancelar,
 * reativar, reembolsar e expirar. Nenhuma requisicao muda o estado direto:
 * tudo passa por aqui e pela consolidacao (SubscriptionLifecycle).
 *
 * Assinatura do Stripe: a acao vai PRIMEIRO ao Stripe (chave de
 * idempotencia: clique duplo nao repete), e o estado local e consolidado a
 * partir da RESPOSTA do Stripe. Se o Stripe recusar, nada muda aqui.
 * Assinatura manual (importada): a acao e so local.
 */
final class SubscriptionManager
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SubscriptionLifecycle $life,
    ) {}

    /**
     * Cancelar: no fim do periodo pago (beneficio ate la) ou imediatamente
     * (beneficio termina hoje). O cliente so cancela no fim do periodo.
     *
     * @throws SubscriptionRuleViolation
     */
    public function cancel(Subscription $subscription, bool $immediately, ?string $reason, User|Customer $actor): Subscription
    {
        $motivo = trim((string) $reason);
        $origem = $actor instanceof User ? EventSource::Staff : EventSource::Customer;
        if ($actor instanceof User && mb_strlen($motivo) < 3) {
            throw new SubscriptionRuleViolation('reason_required');
        }
        $s = $subscription->refresh();
        if ($actor instanceof Customer && ($immediately || $s->customer_id !== $actor->id || $s->status === SubscriptionStatus::Pending)) {
            throw new SubscriptionRuleViolation('invalid_transition');
        }
        if ($s->status === null || $s->status->isFinal() || ($s->status === SubscriptionStatus::CancelScheduled && ! $immediately)) {
            throw new SubscriptionRuleViolation('invalid_transition');
        }
        $motivo = $motivo !== '' ? mb_substr($motivo, 0, 255) : null;

        // Aguardando pagamento: o link deixa de valer.
        if ($s->status === SubscriptionStatus::Pending) {
            if ($s->checkout_session_id !== null) {
                $this->stripe->expireCheckoutSession($s->checkout_session_id, 'expire-'.$s->public_id);
            }

            return $this->local($s, function (Subscription $t) use ($motivo, $actor, $origem): void {
                $this->markCancel($t, $motivo, $actor, $origem);
                $t->cancelled_at = BusinessTime::now();
                $t->cancel_effective_on = CarbonImmutable::parse(BusinessTime::today());
                $this->life->transition($t, SubscriptionStatus::Cancelled, SubscriptionEventKind::Cancelled, $origem, $actor, $motivo ?? 'Link de pagamento cancelado.');
            }, true);
        }

        if ($s->isStripe() && $s->gateway_subscription_id !== null) {
            $chave = 'cancel-'.$s->public_id.'-'.($immediately ? 'now' : 'end').'-v'.$s->version;
            $resposta = $immediately
                ? $this->stripe->cancelNow($s->gateway_subscription_id, $chave)
                : $this->stripe->setCancelAtPeriodEnd($s->gateway_subscription_id, true, $chave);

            return $this->local($s, function (Subscription $t) use ($resposta, $motivo, $actor, $origem): void {
                $this->markCancel($t, $motivo, $actor, $origem);
                $this->life->applyStripeSnapshot($t, $resposta, BusinessTime::now(), $origem, $actor, null, $motivo);
            }, $immediately);
        }

        return $this->local($s, function (Subscription $t) use ($immediately, $motivo, $actor, $origem): void {
            $this->markCancel($t, $motivo, $actor, $origem);
            if (! $immediately) {
                $t->cancel_at_period_end = true;
                $this->life->transition($t, SubscriptionStatus::CancelScheduled, SubscriptionEventKind::CancelScheduled, $origem, $actor, $motivo);

                return;
            }
            $hoje = CarbonImmutable::parse(BusinessTime::today());
            $t->cancelled_at = BusinessTime::now();
            $t->cancel_effective_on = $hoje;
            if ($t->ends_on !== null && $t->ends_on->toDateString() >= $hoje->toDateString()) {
                $t->ends_on = $hoje->subDay();
            }
            $this->life->transition($t, SubscriptionStatus::Cancelled, SubscriptionEventKind::Cancelled, $origem, $actor, $motivo);
        }, $immediately);
    }

    /**
     * Desfaz o cancelamento agendado (antes do fim do periodo).
     *
     * @throws SubscriptionRuleViolation
     */
    public function reactivate(Subscription $subscription, User|Customer $actor): Subscription
    {
        $s = $subscription->refresh();
        if ($actor instanceof Customer && $s->customer_id !== $actor->id) {
            throw new SubscriptionRuleViolation('invalid_transition');
        }
        if ($s->status !== SubscriptionStatus::CancelScheduled || $s->ends_on === null || $s->ends_on->toDateString() < BusinessTime::today()) {
            throw new SubscriptionRuleViolation('invalid_transition');
        }
        $origem = $actor instanceof User ? EventSource::Staff : EventSource::Customer;

        if ($s->isStripe() && $s->gateway_subscription_id !== null) {
            $resposta = $this->stripe->setCancelAtPeriodEnd($s->gateway_subscription_id, false, 'reactivate-'.$s->public_id.'-v'.$s->version);
            $t = DB::transaction(fn () => tap($this->life->lock($s->id), fn (Subscription $t) => $this->life->applyStripeSnapshot($t, $resposta, BusinessTime::now(), $origem, $actor)));
        } else {
            $t = DB::transaction(function () use ($s, $origem, $actor): Subscription {
                $t = $this->life->lock($s->id);
                $t->forceFill(['cancel_at_period_end' => false, 'cancel_requested_at' => null, 'cancel_source' => null, 'cancel_reason' => null, 'cancelled_by_user_id' => null]);
                $this->life->transition($t, SubscriptionStatus::Active, SubscriptionEventKind::Reactivated, $origem, $actor);

                return $t;
            });
        }
        AuditTrail::record('subscription.reactivated', $t, $actor instanceof User ? $actor : null, 'Assinatura reativada (cancelamento desfeito).', ['origem' => $origem->value]);

        return $t;
    }

    /**
     * Reembolso (total ou parcial) de um pagamento feito pelo Stripe. O
     * pagamento original nao muda; o reembolso e um registro novo. O direito
     * ao beneficio nao muda sozinho (se for o caso, cancele tambem).
     *
     * @throws SubscriptionRuleViolation
     */
    public function refund(SubscriptionPayment $payment, int $amountCents, string $reason, User $actor, string $key): SubscriptionRefund
    {
        $existente = SubscriptionRefund::query()->where('request_key', $key)->first();
        if ($existente !== null) {
            return $existente;
        }
        $motivo = trim($reason);
        if (mb_strlen($motivo) < 3) {
            throw new SubscriptionRuleViolation('reason_required');
        }
        if ($payment->gateway !== 'stripe' || $payment->payment_intent_id === null) {
            throw new SubscriptionRuleViolation('refund_not_supported');
        }

        // 1) Reserva o valor (conta como reembolsado enquanto o Stripe responde).
        $r = DB::transaction(function () use ($payment, $amountCents, $motivo, $actor, $key): SubscriptionRefund {
            if ($payment->subscription_id !== null) {
                $this->life->lock($payment->subscription_id);
            }
            $p = SubscriptionPayment::query()->findOrFail($payment->id);
            if ($amountCents < 1 || $amountCents > $p->refundableCents()) {
                throw new SubscriptionRuleViolation('refund_amount', 'Disponível: '.Money::fromCents($p->refundableCents())->format().'.');
            }

            return SubscriptionRefund::query()->create([
                'subscription_payment_id' => $p->id,
                'subscription_id' => $p->subscription_id,
                'customer_id' => $p->customer_id,
                'amount_cents' => $amountCents,
                'status' => 'pending',
                'reason' => mb_substr($motivo, 0, 255),
                'source' => 'staff',
                'requested_by_user_id' => $actor->id,
                'request_key' => $key,
                'created_at' => BusinessTime::now(),
            ]);
        });

        // 2) Stripe (idempotente pela chave do pedido).
        try {
            $resposta = $this->stripe->refund((string) $payment->payment_intent_id, $amountCents, ['local_refund' => (string) $r->id], 'refund-'.$key);
        } catch (SubscriptionRuleViolation $e) {
            $r->forceFill(['status' => 'failed'])->save();
            AuditTrail::record('subscription.refund_failed', $r, $actor, 'Reembolso recusado pelo Stripe: '.Money::fromCents($amountCents)->format().'.', ['motivo' => $motivo]);
            throw $e;
        }

        // 3) Situacao final.
        return DB::transaction(function () use ($r, $resposta, $actor, $motivo, $amountCents, $payment): SubscriptionRefund {
            if ($payment->subscription_id !== null) {
                $this->life->lock($payment->subscription_id);
            }
            $r->refresh();
            $status = match ((string) ($resposta['status'] ?? '')) {
                'succeeded' => 'succeeded', 'pending', 'requires_action' => 'pending', default => 'failed',
            };
            $r->forceFill([
                'status' => $status,
                'gateway_refund_id' => $r->gateway_refund_id ?? (is_string($resposta['id'] ?? null) ? $resposta['id'] : null),
                'refunded_at' => $status === 'succeeded' ? ($r->refunded_at ?? BusinessTime::now()) : null,
            ])->save();
            if ($payment->subscription_id !== null && ($s = Subscription::query()->find($payment->subscription_id)) !== null) {
                SubscriptionHistory::record($s, SubscriptionEventKind::Refunded, EventSource::Staff, $s->status, $s->status, $actor, $motivo, null, null,
                    ['reembolso' => $r->id, 'valor_cents' => $amountCents, 'pagamento' => $payment->gateway_payment_id, 'situacao' => $status]);
            }
            AuditTrail::record('subscription.refunded', $r, $actor, 'Reembolso de assinatura: '.Money::fromCents($amountCents)->format().'.', [
                'motivo' => $motivo, 'pagamento' => $payment->gateway_payment_id, 'situacao' => $status,
            ]);

            return $r;
        });
    }

    /**
     * Rotina diaria (app:subscriptions-expire): links de pagamento vencidos e
     * assinaturas MANUAIS (importadas) que passaram do fim pago + tolerancia.
     * As do Stripe seguem o estado do Stripe (o direito ja acaba pela data).
     *
     * @return array{links: int, expiradas: int, canceladas: int}
     */
    public function expireDue(): array
    {
        $agora = BusinessTime::now();
        $hoje = BusinessTime::today();
        $limite = CarbonImmutable::parse($hoje)->subDays(SubscriptionBenefits::GRACE_DAYS)->toDateString();
        $n = ['links' => 0, 'expiradas' => 0, 'canceladas' => 0];

        $links = Subscription::query()->where('status', SubscriptionStatus::Pending->value)
            ->where(fn ($q) => $q->where('checkout_expires_at', '<', $agora->subHour())->orWhere(fn ($x) => $x->whereNull('checkout_expires_at')->where('created_at', '<', $agora->subDays(2))))->pluck('id');
        foreach ($links as $id) {
            DB::transaction(function () use ($id, &$n): void {
                $t = $this->life->lock((int) $id);
                if ($t->status === SubscriptionStatus::Pending && $this->life->transition($t, SubscriptionStatus::Expired, SubscriptionEventKind::Expired, EventSource::System, null, 'Link de pagamento venceu sem pagamento.')) {
                    $n['links']++;
                }
            });
        }

        $manuais = Subscription::query()->where('gateway', 'manual')->whereNotNull('ends_on')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', SubscriptionStatus::Active->value)->where('ends_on', '<', $limite))
                ->orWhere(fn ($b) => $b->whereIn('status', [SubscriptionStatus::CancelScheduled->value, SubscriptionStatus::PastDue->value])->where('ends_on', '<', $hoje)))->pluck('id');
        foreach ($manuais as $id) {
            DB::transaction(function () use ($id, &$n): void {
                $t = $this->life->lock((int) $id);
                if ($t->status === SubscriptionStatus::CancelScheduled) {
                    $t->cancelled_at ??= BusinessTime::now();
                    $t->cancel_effective_on ??= $t->ends_on?->copy()->addDay();
                    $this->life->transition($t, SubscriptionStatus::Cancelled, SubscriptionEventKind::Cancelled, EventSource::System, null, 'Fim do período pago (cancelamento agendado).') && $n['canceladas']++;
                } elseif ($t->status === SubscriptionStatus::Active || $t->status === SubscriptionStatus::PastDue) {
                    $this->life->transition($t, SubscriptionStatus::Expired, SubscriptionEventKind::Expired, EventSource::System, null, 'Fim do período pago sem renovação.') && $n['expiradas']++;
                }
            });
        }

        return $n;
    }

    /**
     * @param  \Closure(Subscription): void  $work
     */
    private function local(Subscription $s, \Closure $work, bool $immediately): Subscription
    {
        $t = DB::transaction(function () use ($s, $work): Subscription {
            $t = $this->life->lock($s->id);
            $work($t);
            $t->save();

            return $t;
        });
        AuditTrail::record($immediately ? 'subscription.cancelled' : 'subscription.cancel_scheduled', $t, $t->cancelled_by_user_id !== null ? User::query()->find($t->cancelled_by_user_id) : null,
            $immediately ? 'Assinatura cancelada imediatamente.' : 'Cancelamento agendado para o fim do período pago.', [
                'motivo' => $t->cancel_reason, 'origem' => $t->cancel_source?->value, 'direito_ate' => $t->ends_on?->toDateString(),
            ]);

        return $t;
    }

    private function markCancel(Subscription $t, ?string $reason, User|Customer $actor, EventSource $source): void
    {
        $t->cancel_requested_at = BusinessTime::now();
        $t->cancel_source = $source;
        $t->cancel_reason = $reason;
        $t->cancelled_by_user_id = $actor instanceof User ? $actor->id : null;
    }
}

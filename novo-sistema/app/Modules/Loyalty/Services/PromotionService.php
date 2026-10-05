<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Pricing\PromotionCandidate;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Pricing\PromotionQuote;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Support\Money;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * O UNICO lugar que grava promocoes (promocoes.md §4): aplica o desconto
 * escolhido pelo PromotionEngine, reserva o cupom ou os pontos, libera e
 * consome as reservas. Sempre DENTRO da transacao do chamador (agendamento,
 * atendimento, conclusao, cancelamento): nada fica pela metade.
 *
 * Ciclo de cupom e pontos (decisao do dono para os pontos):
 *   reservado (ao agendar ou aplicar no balcao)
 *     -> usado     (na conclusao do atendimento: cupom conta o uso; pontos saem do saldo)
 *     -> liberado  (cancelamento, falta, desconto maior, desconto retirado)
 *
 * Travas: cliente (pontos) e depois cupom (linha do cupom, version), sempre
 * nesta ordem, depois das travas do chamador (agenda ou atendimento).
 */
final class PromotionService
{
    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly LoyaltyLedger $ledger,
    ) {}

    /**
     * Na criacao do agendamento (BookingService::book), com os itens ja
     * gravados: escolhe e grava o desconto. Pedido invalido (cupom vencido,
     * pontos insuficientes) recusa o agendamento inteiro com o motivo.
     *
     * @throws PromotionRejected
     */
    public function applyToAppointment(Appointment $appointment, ?Customer $customer, PromotionRequest $request): PromotionQuote
    {
        $linhas = PromotionEngine::linesFrom($appointment->items()->get());
        $q = $this->engine->quote($customer, $linhas, BusinessTime::dateOf($appointment->starts_at), $request, $appointment->id);
        if ($q->hasProblems()) {
            throw new PromotionRejected('promotion_rejected', $q->firstProblem());
        }
        $c = $q->chosen;
        if ($c === null) {
            return $q;
        }

        [$cupomUso, $resgate] = $this->reserve($c, $customer, $appointment->id);
        $appointment->adjustments()->create([
            'kind' => $c->kind,
            'amount_cents' => $c->amountCents,
            'discount_type' => $c->rule->type,
            'percent_bp' => $c->rule->type === DiscountType::Percent ? $c->rule->value : null,
            'fixed_cents' => $c->rule->type === DiscountType::Fixed ? $c->rule->value : null,
            'coupon_id' => $c->coupon?->id,
            'coupon_redemption_id' => $cupomUso?->id,
            'loyalty_redemption_id' => $resgate?->id,
            'subscription_id' => $c->subscriptionId,
            'description' => $c->label,
        ]);

        return $q;
    }

    /**
     * Fase 9: reavalia o desconto de um agendamento ja gravado (adesao a
     * assinatura confirmada depois do agendamento). Vale o maior: se o
     * beneficio da assinatura (ou outro automatico) for maior que o atual,
     * substitui e libera a reserva do atual. Devolve o candidato aplicado, ou
     * nulo se nada mudou. Dentro da transacao do chamador.
     */
    public function reapplyToAppointment(Appointment $appointment): ?PromotionCandidate
    {
        $cliente = $appointment->customer_id !== null ? Customer::query()->find($appointment->customer_id) : null;
        if ($cliente === null || $appointment->starts_at === null) {
            return null;
        }
        $linhas = PromotionEngine::linesFrom($appointment->items()->get());
        $atuais = $appointment->adjustments()->get();
        $atual = null;
        if ($atuais->isNotEmpty()) {
            $ultimo = $atuais->last();
            $regra = $ultimo->discount_type !== null
                ? Discount::of($ultimo->discount_type, (int) ($ultimo->discount_type === DiscountType::Percent ? $ultimo->percent_bp : $ultimo->fixed_cents))
                : Discount::fixed(max(1, (int) $ultimo->amount_cents));
            $atual = new PromotionCandidate($ultimo->kind, $regra, (int) $atuais->sum('amount_cents'), 'Desconto atual', isCurrent: true);
        }
        $q = $this->engine->quote($cliente, $linhas, BusinessTime::dateOf($appointment->starts_at), new PromotionRequest(null, false), $appointment->id, $atual);
        $c = $q->chosen;
        if ($c === null || $c->isCurrent) {
            return null;
        }
        foreach ($atuais as $adj) {
            $this->releaseFor($adj->coupon_redemption_id, $adj->loyalty_redemption_id, 'Substituído por desconto maior no agendamento '.$appointment->code);
            $adj->delete();
        }
        [$cupomUso, $resgate] = $this->reserve($c, $cliente, $appointment->id);
        $appointment->adjustments()->create([
            'kind' => $c->kind,
            'amount_cents' => $c->amountCents,
            'discount_type' => $c->rule->type,
            'percent_bp' => $c->rule->type === DiscountType::Percent ? $c->rule->value : null,
            'fixed_cents' => $c->rule->type === DiscountType::Fixed ? $c->rule->value : null,
            'coupon_id' => $c->coupon?->id,
            'coupon_redemption_id' => $cupomUso?->id,
            'loyalty_redemption_id' => $resgate?->id,
            'subscription_id' => $c->subscriptionId,
            'description' => $c->label,
        ]);

        return $c;
    }

    /**
     * No balcao: aplica o desconto pedido se for maior que o atual (R-10).
     * Libera o atual e reserva o novo. Devolve o candidato aplicado.
     *
     * @throws PromotionRejected
     */
    public function applyToAttendance(Attendance $attendance, PromotionRequest $request, ?PromotionCandidate $manual, ?int $actorId, ?string $manualReason = null): PromotionCandidate
    {
        $cliente = $attendance->customer_id !== null ? Customer::query()->find($attendance->customer_id) : null;
        $itens = $attendance->items()->get();
        $linhas = PromotionEngine::linesFrom($itens);
        $atuais = $attendance->discounts()->get();
        $atual = $this->currentCandidate($atuais, $linhas);

        $data = BusinessTime::dateOf($attendance->opened_at ?? BusinessTime::now());
        $q = $this->engine->quote($cliente, $linhas, $data, $request, $attendance->appointment_id, $atual, $manual);
        if ($q->hasProblems()) {
            throw new PromotionRejected('promotion_rejected', $q->firstProblem());
        }
        $c = $q->chosen;
        if ($c === null || $c->isCurrent) {
            throw new PromotionRejected('not_better', $atual !== null ? $atual->label.': '.Money::fromCents($atual->amountCents)->format().'.' : null);
        }

        foreach ($atuais as $d) {
            $this->releaseFor($d->coupon_redemption_id, $d->loyalty_redemption_id, 'Substituído por desconto maior no atendimento '.$attendance->code);
            $d->delete();
        }

        [$cupomUso, $resgate] = $c->kind === AdjustmentKind::Manual ? [null, null] : $this->reserve($c, $cliente, $attendance->appointment_id);
        $attendance->discounts()->create([
            'kind' => $c->kind,
            'type' => $c->rule->type,
            'percent_bp' => $c->rule->type === DiscountType::Percent ? $c->rule->value : null,
            'fixed_cents' => $c->rule->type === DiscountType::Fixed ? $c->rule->value : null,
            'base_cents' => 0,
            'amount_cents' => 0,
            'reason' => $c->kind === AdjustmentKind::Manual ? $manualReason : $c->label,
            'applied_by_user_id' => $actorId,
            'coupon_redemption_id' => $cupomUso?->id,
            'loyalty_redemption_id' => $resgate?->id,
            'subscription_id' => $c->subscriptionId,
        ]);

        return $c;
    }

    /**
     * O desconto ja aplicado no atendimento, como candidato (para comparar).
     *
     * @param  iterable<AttendanceDiscount>  $discounts
     * @param  list<array{total: ?int, discountable: bool, unit: ?int}>  $lines
     */
    public function currentCandidate(iterable $discounts, array $lines): ?PromotionCandidate
    {
        $atual = null;
        $soma = 0;
        foreach ($discounts as $d) {
            /** @var AttendanceDiscount $d */
            $soma += PromotionEngine::amountFor($d->rule(), $lines);
            $atual = $d;
        }
        if ($atual === null) {
            return null;
        }

        return new PromotionCandidate($atual->kind, $atual->rule(), $soma, 'Desconto atual ('.$atual->kind->label().')', isCurrent: true, subscriptionId: $atual->subscription_id);
    }

    /**
     * Na conclusao do atendimento (dentro da transacao): consome as reservas
     * usadas pelos descontos do atendimento (cupom conta o uso; pontos saem do
     * saldo) e libera as outras reservas do agendamento.
     */
    public function finalizeForAttendance(Attendance $attendance): void
    {
        $usadosCupom = [];
        $usadosPontos = [];
        foreach ($attendance->discounts()->get() as $d) {
            if ($d->coupon_redemption_id !== null) {
                $u = CouponRedemption::query()->find($d->coupon_redemption_id);
                if ($u !== null && $u->status === RedemptionStatus::Reserved) {
                    $u->forceFill(['status' => RedemptionStatus::Redeemed, 'attendance_id' => $attendance->id, 'redeemed_at' => BusinessTime::now()])->save();
                }
                $usadosCupom[] = $d->coupon_redemption_id;
            }
            if ($d->loyalty_redemption_id !== null) {
                $r = LoyaltyRedemption::query()->find($d->loyalty_redemption_id);
                if ($r !== null && $r->status === RedemptionStatus::Reserved) {
                    $this->ledger->lock($r->customer_id);
                    if ($this->ledger->balance($r->customer_id) < $r->points) {
                        throw new PromotionRejected('insufficient_points', 'O cliente não tem mais os pontos do resgate.');
                    }
                    $this->ledger->record($r->customer_id, -$r->points, LoyaltyEntryKind::Redeemed, 'Resgate no atendimento '.$attendance->code, [
                        'attendance_id' => $attendance->id, 'appointment_id' => $attendance->appointment_id, 'loyalty_redemption_id' => $r->id,
                    ]);
                    $r->forceFill(['status' => RedemptionStatus::Redeemed, 'attendance_id' => $attendance->id, 'redeemed_at' => BusinessTime::now()])->save();
                }
                $usadosPontos[] = $d->loyalty_redemption_id;
            }
        }

        if ($attendance->appointment_id !== null) {
            $this->releaseForAppointment($attendance->appointment_id, 'Não usado na conclusão do atendimento '.$attendance->code, $usadosCupom, $usadosPontos);
        }
    }

    /**
     * Cancelamento ou falta do agendamento: libera o que estava reservado.
     *
     * @param  list<int>  $exceptCoupon
     * @param  list<int>  $exceptLoyalty
     */
    public function releaseForAppointment(int $appointmentId, string $reason, array $exceptCoupon = [], array $exceptLoyalty = []): void
    {
        foreach (CouponRedemption::query()->where('appointment_id', $appointmentId)->where('status', RedemptionStatus::Reserved)->whereNotIn('id', $exceptCoupon)->pluck('id') as $id) {
            $this->releaseFor((int) $id, null, $reason);
        }
        foreach (LoyaltyRedemption::query()->where('appointment_id', $appointmentId)->where('status', RedemptionStatus::Reserved)->whereNotIn('id', $exceptLoyalty)->pluck('id') as $id) {
            $this->releaseFor(null, (int) $id, $reason);
        }
    }

    /** Libera um uso de cupom e/ou um resgate de pontos reservados. */
    public function releaseFor(?int $couponRedemptionId, ?int $loyaltyRedemptionId, string $reason): void
    {
        if ($couponRedemptionId !== null) {
            $u = CouponRedemption::query()->find($couponRedemptionId);
            if ($u !== null && $u->status === RedemptionStatus::Reserved) {
                DB::table('coupons')->where('id', $u->coupon_id)->increment('version');
                $u->forceFill(['status' => RedemptionStatus::Released, 'active_key' => null, 'released_at' => BusinessTime::now(), 'release_reason' => mb_substr($reason, 0, 255)])->save();
                DB::table('coupons')->where('id', $u->coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
            }
        }
        if ($loyaltyRedemptionId !== null) {
            $r = LoyaltyRedemption::query()->find($loyaltyRedemptionId);
            if ($r !== null && $r->status === RedemptionStatus::Reserved) {
                $r->forceFill(['status' => RedemptionStatus::Released, 'active_key' => null, 'released_at' => BusinessTime::now(), 'release_reason' => mb_substr($reason, 0, 255)])->save();
            }
        }
    }

    /**
     * Reserva o cupom ou os pontos do candidato (trava e confere de novo).
     *
     * @return array{0: ?CouponRedemption, 1: ?LoyaltyRedemption}
     *
     * @throws PromotionRejected
     */
    private function reserve(PromotionCandidate $c, ?Customer $customer, ?int $appointmentId): array
    {
        if ($c->kind === AdjustmentKind::Coupon && $c->coupon !== null) {
            DB::table('coupons')->where('id', $c->coupon->id)->increment('version');
            $cupom = Coupon::withTrashed()->findOrFail($c->coupon->id);
            $problema = $this->engine->couponProblem($cupom, $customer, $appointmentId);
            if ($problema !== null || $customer === null) {
                throw new PromotionRejected('promotion_rejected', $problema ?? 'O cupom exige cliente cadastrado.');
            }
            $existente = CouponRedemption::query()->where('coupon_id', $cupom->id)->where('appointment_id', $appointmentId)->where('status', RedemptionStatus::Reserved)->first();
            if ($existente !== null) {
                return [$existente, null];
            }
            DB::table('coupons')->where('id', $cupom->id)->increment('uses_count');

            return [CouponRedemption::query()->create([
                'coupon_id' => $cupom->id,
                'customer_id' => $customer->id,
                'appointment_id' => $appointmentId,
                'status' => RedemptionStatus::Reserved,
                'active_key' => CouponRedemption::activeKey($cupom->id, $customer->id),
                'discount_cents' => $c->amountCents,
                'reserved_at' => BusinessTime::now(),
            ]), null];
        }

        if ($c->kind === AdjustmentKind::Loyalty && $customer !== null) {
            $this->ledger->lock($customer->id);
            $pontos = $c->loyaltyPoints();
            if ($this->ledger->available($customer, $appointmentId) < $pontos) {
                throw new PromotionRejected('promotion_rejected', 'Pontos insuficientes: há pontos prometidos em outro agendamento.');
            }
            $existente = $appointmentId !== null ? LoyaltyRedemption::query()->where('active_key', LoyaltyRedemption::activeKey($appointmentId))->first() : null;
            if ($existente !== null) {
                return [null, $existente];
            }

            return [null, LoyaltyRedemption::query()->create([
                'customer_id' => $customer->id,
                'points' => $pontos,
                'status' => RedemptionStatus::Reserved,
                'active_key' => $appointmentId !== null ? LoyaltyRedemption::activeKey($appointmentId) : null,
                'appointment_id' => $appointmentId,
                'reward' => $c->loyaltyReward ?? [],
                'discount_cents' => $c->amountCents,
                'reserved_at' => BusinessTime::now(),
            ])];
        }

        return [null, null];
    }

    /** Auditoria de quem aplicou promocao no balcao. */
    public function audit(Attendance $attendance, PromotionCandidate $c, ?User $actor): void
    {
        AuditTrail::record('promotion.applied', $attendance, $actor, $c->label.' aplicado no atendimento '.$attendance->code.'.', [
            'tipo' => $c->kind->value, 'valor_cents' => $c->amountCents,
        ]);
    }
}

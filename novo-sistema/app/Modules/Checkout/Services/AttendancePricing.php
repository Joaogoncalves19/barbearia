<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;

/**
 * Totais do atendimento: o mesmo PriceBreakdown do agendamento, sobre os
 * itens fotografados e os descontos na ordem em que foram aplicados.
 * Enquanto o atendimento esta aberto, o valor de cada desconto e
 * recalculado quando os itens mudam; na conclusao, congela.
 *
 * Assinatura (Fase 9): o beneficio acompanha os itens enquanto o atendimento
 * esta aberto (servico incluido no plano acrescentado na comanda tambem sai
 * de graca, como no sistema antigo); sem servico coberto, o desconto sai.
 */
final class AttendancePricing
{
    public function __construct(private readonly SubscriptionBenefits $benefits) {}

    public function breakdown(Attendance $attendance): PriceBreakdown
    {
        $itens = $attendance->items()->get();
        $descontos = $attendance->discounts()->get();

        return PriceBreakdown::calculate(
            $itens->map(fn ($i) => ['total' => $i->total_cents, 'discountable' => $i->isDiscountable()]),
            $descontos->map(fn (AttendanceDiscount $d) => $d->rule())->values()->all(),
        );
    }

    /** Regrava base e valor de cada desconto (dentro da transacao do chamador). */
    public function refreshDiscounts(Attendance $attendance): PriceBreakdown
    {
        $this->refreshSubscriptionBenefit($attendance);
        $b = $this->breakdown($attendance);
        foreach ($attendance->discounts()->get()->values() as $n => $d) {
            $aplicado = $b->applied[$n] ?? null;
            if ($aplicado === null) {
                continue;
            }
            $d->base_cents = $aplicado['base']->cents;
            $d->amount_cents = $aplicado['amount']->cents;
            if ($d->isDirty()) {
                $d->save();
            }
        }

        return $b;
    }

    private function refreshSubscriptionBenefit(Attendance $attendance): void
    {
        $linhas = null;
        foreach ($attendance->discounts()->get() as $d) {
            if ($d->kind !== AdjustmentKind::Subscription || $d->subscription_id === null) {
                continue;
            }
            $assinatura = Subscription::query()->find($d->subscription_id);
            $linhas ??= PromotionEngine::linesFrom($attendance->items()->get());
            $valor = $assinatura !== null ? $this->benefits->coveredAmount($assinatura, $linhas) : 0;
            if ($valor <= 0) {
                $d->delete();

                continue;
            }
            if ($d->fixed_cents !== $valor) {
                $d->fixed_cents = $valor;
                $d->save();
            }
        }
    }
}

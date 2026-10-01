<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Shared\Pricing\PriceBreakdown;

/**
 * Totais do atendimento: o mesmo PriceBreakdown do agendamento, sobre os
 * itens fotografados e os descontos na ordem em que foram aplicados.
 * Enquanto o atendimento esta aberto, o valor de cada desconto e
 * recalculado quando os itens mudam; na conclusao, congela.
 */
final class AttendancePricing
{
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
}

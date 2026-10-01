<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Shared\Support\Money;

/**
 * Totais do agendamento (o orcamento) a partir dos itens (precos
 * fotografados) e dos descontos. O calculo e o do PriceBreakdown, o mesmo do
 * atendimento: o desconto incide so sobre servicos e combos (nunca sobre
 * produtos) e nunca deixa o total negativo.
 *
 * Se algum item tem preco desconhecido (legado), os totais ficam nulos:
 * nao se apresenta como exato um valor que nao e.
 */
class AppointmentPricing
{
    /**
     * @return array{subtotal: ?Money, discount: ?Money, total: ?Money}
     */
    public function totals(Appointment $appointment): array
    {
        $linhas = $appointment->items()->get(['item_type', 'total_cents'])
            ->map(fn ($i) => ['total' => $i->total_cents, 'discountable' => $i->item_type !== ItemType::Product]);
        // A regra do desconto (Fase 8: percentual ou valor, a mesma que o
        // motor de promocoes calculou); legado sem regra = o valor gravado.
        $descontos = $appointment->adjustments()->orderBy('id')->get()
            ->filter(fn ($a) => (int) $a->amount_cents > 0)
            ->map(fn ($a) => $a->discount_type !== null
                ? Discount::of($a->discount_type, (int) ($a->discount_type === DiscountType::Percent ? $a->percent_bp : $a->fixed_cents))
                : Discount::fixed((int) $a->amount_cents))->values()->all();

        $b = PriceBreakdown::calculate($linhas, $descontos);

        return ['subtotal' => $b->subtotal, 'discount' => $b->discount, 'total' => $b->total];
    }

    /** Grava os totais no agendamento (fotografia do orcamento). */
    public function refresh(Appointment $appointment): Appointment
    {
        $t = $this->totals($appointment);
        $appointment->forceFill([
            'subtotal_cents' => $t['subtotal']?->cents,
            'discount_cents' => $t['discount']?->cents,
            'total_cents' => $t['total']?->cents,
        ])->save();

        return $appointment;
    }
}

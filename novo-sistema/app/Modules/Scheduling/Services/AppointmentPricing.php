<?php

namespace App\Modules\Scheduling\Services;

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
        $descontos = $appointment->adjustments()->orderBy('id')->pluck('amount_cents')
            ->filter(fn ($c) => (int) $c > 0)
            ->map(fn ($c) => Discount::fixed((int) $c))->values()->all();

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

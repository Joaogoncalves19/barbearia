<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Support\Money;

/**
 * Totais do atendimento a partir dos itens (precos fotografados) e dos
 * descontos. Regra herdada do sistema atual: o desconto incide so sobre
 * servicos e combos (nunca sobre produtos) e fica limitado ao valor deles,
 * entao nunca deixa o total negativo.
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
        $itens = $appointment->items()->get(['item_type', 'total_cents']);
        if ($itens->contains(fn ($i) => $i->total_cents === null)) {
            return ['subtotal' => null, 'discount' => null, 'total' => null];
        }

        $subtotal = Money::fromCents((int) $itens->sum('total_cents'));
        $baseDesconto = Money::fromCents((int) $itens->reject(fn ($i) => $i->item_type === ItemType::Product)->sum('total_cents'));
        $descontoBruto = Money::fromCents((int) $appointment->adjustments()->sum('amount_cents'));
        $desconto = $descontoBruto->greaterThan($baseDesconto) ? $baseDesconto : $descontoBruto;

        return ['subtotal' => $subtotal, 'discount' => $desconto, 'total' => $subtotal->subtract($desconto)];
    }

    /** Grava os totais no agendamento (fotografia para relatorios). */
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

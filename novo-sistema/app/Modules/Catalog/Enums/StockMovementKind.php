<?php

namespace App\Modules\Catalog\Enums;

/**
 * Tipos de movimentacao de estoque (estoque.md). O sinal fica na quantidade:
 * entrada > 0, saida < 0. Ajuste, reversao e o ajuste da migracao podem ter
 * qualquer sinal.
 */
enum StockMovementKind: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Consumption = 'consumption';
    case Usage = 'usage';
    case Loss = 'loss';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case LegacyOpening = 'legacy_opening';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Entrada',
            self::Sale => 'Venda',
            self::Consumption => 'Consumo no atendimento',
            self::Usage => 'Saída',
            self::Loss => 'Perda',
            self::Adjustment => 'Ajuste de inventário',
            self::Reversal => 'Estorno de movimentação',
            self::LegacyOpening => 'Ajuste de migração',
        };
    }

    /** +1 entrada, -1 saida, 0 qualquer sinal. */
    public function sign(): int
    {
        return match ($this) {
            self::Purchase => 1,
            self::Sale, self::Consumption, self::Usage, self::Loss => -1,
            self::Adjustment, self::Reversal, self::LegacyOpening => 0,
        };
    }

    /** Nasce de um atendimento (e so dele). */
    public function comesFromAttendance(): bool
    {
        return $this === self::Sale || $this === self::Consumption;
    }

    /** Pode ser revertido por um estorno (uma vez). */
    public function isReversible(): bool
    {
        return ! in_array($this, [self::Reversal, self::LegacyOpening, self::Adjustment], true);
    }
}

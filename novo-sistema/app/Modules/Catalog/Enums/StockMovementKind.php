<?php

namespace App\Modules\Catalog\Enums;

enum StockMovementKind: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case Loss = 'loss';
    case LegacyOpening = 'legacy_opening';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Entrada',
            self::Sale => 'Venda',
            self::Adjustment => 'Ajuste',
            self::Loss => 'Perda',
            self::LegacyOpening => 'Ajuste de migração',
        };
    }
}

<?php

namespace App\Modules\Loyalty\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentual',
            self::Fixed => 'Valor fixo',
        };
    }
}

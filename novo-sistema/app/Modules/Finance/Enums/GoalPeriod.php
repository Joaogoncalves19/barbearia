<?php

namespace App\Modules\Finance\Enums;

enum GoalPeriod: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diária',
            self::Monthly => 'Mensal',
        };
    }
}

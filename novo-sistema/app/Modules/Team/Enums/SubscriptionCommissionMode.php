<?php

namespace App\Modules\Team\Enums;

enum SubscriptionCommissionMode: string
{
    case Default = 'default';
    case Percent = 'percent';
    case Fixed = 'fixed';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Padrão do profissional',
            self::Percent => 'Percentual próprio',
            self::Fixed => 'Valor fixo',
            self::None => 'Sem comissão',
        };
    }
}

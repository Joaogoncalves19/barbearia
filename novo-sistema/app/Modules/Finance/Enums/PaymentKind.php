<?php

namespace App\Modules\Finance\Enums;

enum PaymentKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Pagamento',
            self::Refund => 'Estorno',
        };
    }
}

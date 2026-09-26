<?php

namespace App\Modules\Loyalty\Enums;

enum GiftCardStatus: string
{
    case Available = 'available';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponível',
            self::Redeemed => 'Utilizado',
            self::Expired => 'Expirado',
            self::Cancelled => 'Cancelado',
        };
    }
}

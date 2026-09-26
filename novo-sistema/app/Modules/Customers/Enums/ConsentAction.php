<?php

namespace App\Modules\Customers\Enums;

enum ConsentAction: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Aceite',
            self::Revoked => 'Revogação',
        };
    }
}

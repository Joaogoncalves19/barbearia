<?php

namespace App\Modules\Customers\Enums;

enum MarketingConsent: string
{
    case Unknown = 'unknown';
    case Granted = 'granted';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Desconhecido',
            self::Granted => 'Aceito',
            self::Revoked => 'Recusado',
        };
    }
}

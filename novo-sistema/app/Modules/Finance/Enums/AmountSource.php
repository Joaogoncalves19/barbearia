<?php

namespace App\Modules\Finance\Enums;

enum AmountSource: string
{
    case Recorded = 'recorded';
    case LegacyEstimated = 'legacy_estimated';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Registrado',
            self::LegacyEstimated => 'Estimado (sistema antigo)',
        };
    }
}

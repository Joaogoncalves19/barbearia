<?php

namespace App\Modules\Team\Enums;

enum TimeOffKind: string
{
    case DayOff = 'day_off';
    case Vacation = 'vacation';
    case Medical = 'medical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DayOff => 'Folga',
            self::Vacation => 'Férias',
            self::Medical => 'Atestado',
            self::Other => 'Outro',
        };
    }
}

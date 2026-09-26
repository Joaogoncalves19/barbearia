<?php

namespace App\Modules\Scheduling\Enums;

enum ReminderKind: string
{
    case DayBefore = 'day_before';
    case HoursBefore = 'hours_before';

    public function label(): string
    {
        return match ($this) {
            self::DayBefore => 'Véspera',
            self::HoursBefore => 'Horas antes',
        };
    }
}

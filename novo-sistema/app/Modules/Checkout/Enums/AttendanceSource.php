<?php

namespace App\Modules\Checkout\Enums;

enum AttendanceSource: string
{
    case Appointment = 'appointment';
    case WalkIn = 'walk_in';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Appointment => 'Agendamento',
            self::WalkIn => 'Encaixe',
            self::Legacy => 'Sistema antigo',
        };
    }
}

<?php

namespace App\Modules\Scheduling\Enums;

enum CancelledBy: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Cliente',
            self::Staff => 'Barbearia',
            self::System => 'Sistema',
        };
    }
}

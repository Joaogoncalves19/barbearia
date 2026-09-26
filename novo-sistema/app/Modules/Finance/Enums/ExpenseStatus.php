<?php

namespace App\Modules\Finance\Enums;

enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Paga',
            self::Cancelled => 'Cancelada',
        };
    }
}

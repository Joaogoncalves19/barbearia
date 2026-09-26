<?php

namespace App\Modules\Customers\Enums;

enum MergeCandidateStatus: string
{
    case Pending = 'pending';
    case Merged = 'merged';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Merged => 'Mesclado',
            self::Dismissed => 'Descartado',
        };
    }
}

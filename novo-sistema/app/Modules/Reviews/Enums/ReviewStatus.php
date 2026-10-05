<?php

namespace App\Modules\Reviews\Enums;

/** Moderacao da avaliacao (D-48: so e publicada depois de aprovada). */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando revisão',
            self::Approved => 'Publicada',
            self::Rejected => 'Recusada',
        };
    }
}

<?php

namespace App\Modules\Subscriptions\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case CancelScheduled = 'cancel_scheduled';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::CancelScheduled => 'Cancelamento agendado',
            self::Expired => 'Expirada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Enquanto vale, ocupa a vaga de "uma assinatura ativa por cliente". */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Active, self::CancelScheduled], true);
    }
}

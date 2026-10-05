<?php

namespace App\Modules\Subscriptions\Enums;

/** Quem originou uma mudanca na assinatura (historico e cancelamento). */
enum EventSource: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Stripe = 'stripe';
    case System = 'system';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Cliente',
            self::Staff => 'Equipe',
            self::Stripe => 'Stripe',
            self::System => 'Sistema',
            self::Import => 'Importação',
        };
    }
}

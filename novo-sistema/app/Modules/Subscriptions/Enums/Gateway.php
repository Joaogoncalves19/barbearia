<?php

namespace App\Modules\Subscriptions\Enums;

enum Gateway: string
{
    case Manual = 'manual';
    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Stripe => 'Stripe',
        };
    }
}

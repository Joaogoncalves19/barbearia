<?php

namespace App\Modules\Scheduling\Enums;

enum AdjustmentKind: string
{
    case Coupon = 'coupon';
    case GiftCard = 'gift_card';
    case Loyalty = 'loyalty';
    case Birthday = 'birthday';
    case Referral = 'referral';
    case Subscription = 'subscription';
    case PlanSignup = 'plan_signup';
    case Manual = 'manual';
    case LegacyUnknown = 'legacy_unknown';

    public function label(): string
    {
        return match ($this) {
            self::Coupon => 'Cupom',
            self::GiftCard => 'Vale-presente',
            self::Loyalty => 'Fidelidade',
            self::Birthday => 'Aniversário',
            self::Referral => 'Indicação',
            self::Subscription => 'Assinatura',
            self::PlanSignup => 'Adesão a plano',
            self::Manual => 'Manual',
            self::LegacyUnknown => 'Desconhecido (sistema antigo)',
        };
    }
}

<?php

namespace App\Modules\Loyalty\Enums;

enum LoyaltyEntryKind: string
{
    case Earned = 'earned';
    case Redeemed = 'redeemed';
    case ReferralBonus = 'referral_bonus';
    case Adjustment = 'adjustment';
    case LegacyHistory = 'legacy_history';
    case LegacyOpening = 'legacy_opening';

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Ganho',
            self::Redeemed => 'Resgate',
            self::ReferralBonus => 'Bônus de indicação',
            self::Adjustment => 'Ajuste',
            self::LegacyHistory => 'Histórico (sistema antigo)',
            self::LegacyOpening => 'Ajuste de migração',
        };
    }
}

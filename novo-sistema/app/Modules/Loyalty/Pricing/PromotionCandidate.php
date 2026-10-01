<?php

namespace App\Modules\Loyalty\Pricing;

use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Shared\Pricing\Discount;

/**
 * Um desconto possivel (cupom, pontos, aniversario, indicacao, manual ou o
 * que ja esta aplicado), com a regra e quanto vale sobre os itens.
 */
final class PromotionCandidate
{
    /**
     * @param  array<string, int|string|null>|null  $loyaltyReward  fotografia da recompensa (pontos, tipo, base...)
     */
    public function __construct(
        public readonly AdjustmentKind $kind,
        public readonly Discount $rule,
        public readonly int $amountCents,
        public readonly string $label,
        public readonly ?Coupon $coupon = null,
        public readonly ?array $loyaltyReward = null,
        public readonly bool $isCurrent = false,
    ) {}

    /** Desempate (mesmo valor): o que ja esta aplicado; depois o que nao gasta nada (aniversario, indicacao); depois cupom, pontos, manual. */
    public function priority(): int
    {
        if ($this->isCurrent) {
            return 0;
        }

        return match ($this->kind) {
            AdjustmentKind::Birthday => 1,
            AdjustmentKind::Referral => 2,
            AdjustmentKind::Coupon => 3,
            AdjustmentKind::Loyalty => 4,
            default => 5,
        };
    }

    public function loyaltyPoints(): int
    {
        return (int) ($this->loyaltyReward['pontos'] ?? 0);
    }
}

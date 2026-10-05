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
        public readonly ?int $subscriptionId = null,
    ) {}

    /** Desempate (mesmo valor): o que ja esta aplicado; depois o que nao gasta nada (assinatura, aniversario, indicacao); depois cupom, pontos, manual. */
    public function priority(): int
    {
        if ($this->isCurrent) {
            return 0;
        }

        return match ($this->kind) {
            AdjustmentKind::Subscription => 1,
            AdjustmentKind::Birthday => 2,
            AdjustmentKind::Referral => 3,
            AdjustmentKind::Coupon => 4,
            AdjustmentKind::Loyalty => 5,
            default => 6,
        };
    }

    public function loyaltyPoints(): int
    {
        return (int) ($this->loyaltyReward['pontos'] ?? 0);
    }
}

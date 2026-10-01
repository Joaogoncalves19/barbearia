<?php

namespace App\Modules\Loyalty\Pricing;

use App\Modules\Shared\Pricing\PriceBreakdown;

/**
 * Resposta do motor: o desconto escolhido (o maior; R-10), os outros
 * considerados, por que um pedido foi recusado e os totais com o escolhido
 * (o mesmo PriceBreakdown da gravacao: orcamento exibido = valor gravado).
 */
final class PromotionQuote
{
    /**
     * @param  list<PromotionCandidate>  $candidates
     * @param  array<string, string>  $problems  pedido => motivo (coupon, loyalty)
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly ?PromotionCandidate $chosen,
        public readonly array $candidates,
        public readonly array $problems,
        public readonly array $notes,
        public readonly PriceBreakdown $breakdown,
    ) {}

    public function hasProblems(): bool
    {
        return $this->problems !== [];
    }

    public function firstProblem(): ?string
    {
        return $this->problems === [] ? null : array_values($this->problems)[0];
    }

    public function totalCents(): ?int
    {
        return $this->breakdown->total?->cents;
    }
}

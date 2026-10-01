<?php

namespace App\Modules\Loyalty\Pricing;

use App\Modules\Loyalty\Models\Coupon;

/**
 * O que o cliente (ou a equipe, no balcao) pediu: um codigo de cupom e/ou
 * usar os pontos. Aniversario e indicacao nao sao pedidos: o motor os
 * considera sozinho quando o cliente tem direito.
 */
final class PromotionRequest
{
    public readonly ?string $couponCode;

    public function __construct(?string $couponCode = null, public readonly bool $useLoyalty = false)
    {
        $codigo = $couponCode !== null ? Coupon::normalizeCode($couponCode) : '';
        $this->couponCode = $codigo !== '' ? mb_substr($codigo, 0, 64) : null;
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->couponCode === null && ! $this->useLoyalty;
    }
}

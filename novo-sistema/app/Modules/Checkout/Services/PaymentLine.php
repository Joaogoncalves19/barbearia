<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Finance\Enums\PaymentMethod;

/** Uma forma de pagamento informada na conclusao (pagamento dividido = varias). */
final class PaymentLine
{
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly int $amountCents,
        public readonly int $tipCents = 0,
        // Fase 8: vale-presente (forma GiftCard) informa o codigo.
        public readonly ?string $giftCardCode = null,
    ) {}

    public function isGiftCard(): bool
    {
        return $this->method === PaymentMethod::GiftCard;
    }
}

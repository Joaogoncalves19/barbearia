<?php

namespace App\Modules\Loyalty\Exceptions;

use RuntimeException;

/** Promocao, cupom, pontos ou vale-presente recusados; nada foi gravado. */
final class PromotionRejected extends RuntimeException
{
    public const MESSAGES = [
        'promotion_rejected' => 'Promoção não aplicada.',
        'not_better' => 'O desconto atual é maior ou igual: vale um desconto só, o maior.',
        'price_changed' => 'O valor mudou desde que você conferiu (promoção ou preço). Confira o novo valor e confirme de novo.',
        'customer_required' => 'Esta promoção exige cliente cadastrado.',
        'invalid_points' => 'Informe uma quantidade de pontos diferente de zero.',
        'reason_required' => 'Informe o motivo (ao menos 3 caracteres).',
        'insufficient_points' => 'Pontos insuficientes para esta retirada (há pontos prometidos em resgates).',
        'gift_card_not_found' => 'Vale-presente não encontrado.',
        'gift_card_unusable' => 'Este vale-presente não pode ser usado.',
        'gift_card_amount' => 'Vale-presente é de uso único: use o valor do vale, até o total a pagar.',
        'gift_card_tip' => 'Gorjeta não pode ser paga com vale-presente.',
        'invalid_amount' => 'Informe um valor entre R$ 0,01 e R$ 100.000,00.',
        'invalid_method' => 'Forma de pagamento inválida.',
        'already_cancelled' => 'Este vale-presente já foi usado ou cancelado.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        $msg = self::MESSAGES[$reason] ?? 'Operação não permitida.';
        parent::__construct($reason === 'promotion_rejected' && $detail !== null ? $detail : ($detail !== null ? "{$msg} {$detail}" : $msg));
    }
}

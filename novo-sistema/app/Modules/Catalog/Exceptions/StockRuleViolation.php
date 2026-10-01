<?php

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/** Regra de estoque recusou a operacao; nada foi gravado. */
final class StockRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'insufficient' => 'Estoque insuficiente: a operação deixaria o saldo negativo.',
        'invalid_quantity' => 'Informe uma quantidade inteira maior que zero.',
        'reason_required' => 'Informe o motivo.',
        'inactive_product' => 'Produto inativo: reative-o antes de usar em novos lançamentos.',
        'no_change' => 'A contagem é igual ao saldo atual: não há ajuste a fazer.',
        'already_reversed' => 'Esta movimentação já foi estornada.',
        'not_reversible' => 'Este tipo de movimentação não pode ser estornado. Faça um ajuste de inventário.',
        'not_for_sale' => 'Este produto não tem preço de venda: registre-o como consumo.',
    ];

    public function __construct(public readonly string $reason, public readonly ?string $product = null)
    {
        $msg = self::MESSAGES[$reason] ?? 'Operação de estoque não permitida.';
        parent::__construct($product !== null ? "{$product}: {$msg}" : $msg);
    }
}

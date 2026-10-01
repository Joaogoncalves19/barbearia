<?php

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

/** Regra de caixa ou de pagamento recusou a operacao; nada foi gravado. */
final class CashRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'no_open_session' => 'Não há caixa aberto. Abra o caixa antes de receber ou movimentar.',
        'already_open' => 'Já existe um caixa aberto. Feche-o antes de abrir outro.',
        'closed' => 'Este caixa já foi fechado.',
        'invalid_amount' => 'Informe um valor maior que zero.',
        'reason_required' => 'Informe o motivo.',
        'insufficient_cash' => 'A sangria é maior que o dinheiro esperado no caixa.',
        'justification_required' => 'Há diferença entre o contado e o esperado: explique o motivo.',
        'refund_exceeds' => 'O estorno é maior que o valor ainda não estornado deste pagamento.',
        'not_refundable' => 'Só pagamentos de atendimentos concluídos podem ser estornados.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'Operação de caixa não permitida.');
    }
}

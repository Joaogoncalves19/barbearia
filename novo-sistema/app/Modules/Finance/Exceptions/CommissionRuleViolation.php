<?php

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

/** Regra de comissao, gorjeta, vale ou repasse recusou a operacao; nada foi gravado. */
final class CommissionRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'invalid_rule' => 'Regra inválida: percentual entre 0% e 100%, ou valor fixo (só para serviço) entre R$ 0,00 e R$ 100.000,00.',
        'no_change' => 'A regra informada é igual à que já está em vigor.',
        'no_rule' => 'Não há regra em vigor para este escopo.',
        'reason_required' => 'Informe o motivo (ao menos 3 caracteres).',
        'invalid_amount' => 'Informe um valor diferente de zero.',
        'invalid_method' => 'Forma de pagamento inválida para o profissional: dinheiro, Pix ou outro (transferência).',
        'nothing_to_pay' => 'Não há valores em aberto para este profissional até a data informada.',
        'negative_balance' => 'O repasse ficaria negativo: vales, estornos ou ajustes em aberto são maiores que o valor a receber. Aguarde novos atendimentos ou estorne o lançamento indevido.',
        'already_reversed' => 'Este lançamento já foi estornado.',
        'not_reversible' => 'Este lançamento não pode ser estornado.',
        'legacy_payout' => 'Repasse importado do sistema antigo: não tem lançamentos e não pode ser estornado aqui.',
        'unknown_attendance' => 'Atendimento não encontrado ou não concluído.',
        'professional_mismatch' => 'O atendimento informado é de outro profissional.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        $msg = self::MESSAGES[$reason] ?? 'Operação não permitida.';
        parent::__construct($detail !== null ? "{$msg} {$detail}" : $msg);
    }
}

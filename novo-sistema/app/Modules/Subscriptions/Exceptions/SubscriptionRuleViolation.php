<?php

namespace App\Modules\Subscriptions\Exceptions;

use RuntimeException;

/** Regra de assinatura recusada; nada foi gravado. */
final class SubscriptionRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'stripe_not_configured' => 'Pagamento online não configurado: a assinatura online não está disponível.',
        'plan_unavailable' => 'Plano indisponível para novas adesões.',
        'already_subscribed' => 'O cliente já tem uma assinatura vigente.',
        'customer_required' => 'A assinatura exige cliente cadastrado com e-mail.',
        'invalid_transition' => 'Esta ação não é possível na situação atual da assinatura.',
        'reason_required' => 'Informe o motivo (ao menos 3 caracteres).',
        'gateway_error' => 'O Stripe não confirmou a operação. Nada foi alterado; tente de novo.',
        'refund_amount' => 'Valor do reembolso inválido: entre R$ 0,01 e o valor ainda não reembolsado.',
        'refund_not_supported' => 'Este pagamento não foi feito pelo Stripe: o reembolso não é feito pelo sistema.',
        'invalid_plan' => 'Plano inválido: informe nome, preço e ao menos um serviço incluído.',
        'no_change' => 'Nada mudou.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        $msg = self::MESSAGES[$reason] ?? 'Operação não permitida.';
        parent::__construct($detail !== null ? "{$msg} {$detail}" : $msg);
    }
}

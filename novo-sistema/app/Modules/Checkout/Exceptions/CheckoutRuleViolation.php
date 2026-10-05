<?php

namespace App\Modules\Checkout\Exceptions;

use RuntimeException;

/** Regra do atendimento recusou a operacao; nada foi gravado. */
final class CheckoutRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'invalid_status' => 'Este atendimento não pode mais ser alterado.',
        'appointment_not_open' => 'Só agendamentos pendentes ou confirmados podem virar atendimento.',
        'appointment_not_today' => 'O atendimento só pode ser aberto no dia do agendamento.',
        'appointment_without_professional' => 'O agendamento não tem profissional.',
        'contact_required' => 'Informe o cliente: escolha um cadastrado ou digite o nome.',
        'service_unavailable' => 'Serviço inativo ou que este profissional não executa.',
        'professional_inactive' => 'Profissional inativo.',
        'product_inactive' => 'Produto inativo: não pode entrar em atendimentos novos.',
        'not_for_sale' => 'Este produto não tem preço de venda: registre-o como consumo.',
        'invalid_quantity' => 'Informe uma quantidade inteira entre 1 e 99.',
        'reason_required' => 'Informe o motivo.',
        'invalid_discount' => 'Desconto inválido: percentual entre 0,01% e 100% ou valor maior que zero.',
        'no_items' => 'Inclua ao menos um serviço ou produto antes de concluir.',
        'not_started' => 'Inicie o atendimento antes de concluir.',
        'invalid_payment' => 'Pagamento inválido: escolha a forma e informe um valor maior que zero.',
        'payment_mismatch' => 'A soma dos pagamentos precisa ser igual ao total a pagar.',
        'unknown_price' => 'Há item sem preço conhecido: o atendimento não pode ser concluído.',
        'not_in_attendance' => 'Este item não pertence ao atendimento.',
        // Fase 8: o detalhe (motivo da promocao/vale) vem do PromotionRejected.
        'promotion' => '',
        'invalid_gift_card_line' => 'Vale-presente: informe o código do vale e não lance gorjeta nele.',
        'customer_required' => 'Cupom e pontos exigem cliente cadastrado no atendimento.',
        'subscription_lapsed' => 'A assinatura do cliente não dá direito hoje (cancelada ou vencida): retire o desconto da assinatura e confira o novo total.',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        $msg = self::MESSAGES[$reason] ?? 'Operação não permitida.';
        parent::__construct($detail !== null ? trim("{$msg} {$detail}") : $msg);
    }
}

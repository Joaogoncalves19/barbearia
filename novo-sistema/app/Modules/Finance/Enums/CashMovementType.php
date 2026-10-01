<?php

namespace App\Modules\Finance\Enums;

/**
 * Tipos de movimentacao do caixa (caixa.md). Entradas somam, saidas
 * subtraem; o sinal fica no valor (amount_cents). Cada tipo tem UMA origem
 * obrigatoria (ou nenhuma, nos lancamentos manuais).
 */
enum CashMovementType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Supply = 'supply';
    case Withdrawal = 'withdrawal';
    /** Repasse ao profissional pago em dinheiro (Fase 7). */
    case Payout = 'payout';
    /** Estorno de repasse em dinheiro: o dinheiro volta ao caixa. */
    case PayoutReversal = 'payout_reversal';
    /** Vale (adiantamento) pago em dinheiro (Fase 7). */
    case Advance = 'advance';
    /** Estorno de vale em dinheiro: o dinheiro volta ao caixa. */
    case AdvanceReversal = 'advance_reversal';
    /** Venda de vale-presente (Fase 8): o dinheiro entra na venda. */
    case GiftCardSale = 'gift_card_sale';
    /** Cancelamento de vale-presente com devolucao do valor. */
    case GiftCardRefund = 'gift_card_refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Pagamento',
            self::Refund => 'Estorno',
            self::Supply => 'Suprimento',
            self::Withdrawal => 'Sangria',
            self::Payout => 'Repasse ao profissional',
            self::PayoutReversal => 'Estorno de repasse',
            self::Advance => 'Vale',
            self::AdvanceReversal => 'Estorno de vale',
            self::GiftCardSale => 'Venda de vale-presente',
            self::GiftCardRefund => 'Devolução de vale-presente',
        };
    }

    public function isInflow(): bool
    {
        return in_array($this, [self::Payment, self::Supply, self::PayoutReversal, self::AdvanceReversal, self::GiftCardSale], true);
    }

    /** Lancado a mao pela equipe (os outros nascem de um pagamento, repasse ou vale). */
    public function isManual(): bool
    {
        return $this === self::Supply || $this === self::Withdrawal;
    }

    /** Coluna de origem obrigatoria (nula = lancamento manual, sem origem). */
    public function originColumn(): ?string
    {
        return match ($this) {
            self::Payment, self::Refund => 'payment_id',
            self::Payout, self::PayoutReversal => 'commission_payout_id',
            self::Advance, self::AdvanceReversal => 'advance_id',
            self::GiftCardSale, self::GiftCardRefund => 'gift_card_id',
            self::Supply, self::Withdrawal => null,
        };
    }
}

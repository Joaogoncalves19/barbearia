<?php

namespace App\Modules\Finance\Enums;

/**
 * Tipos de movimentacao do caixa (caixa.md). Entradas somam, saidas
 * subtraem; o sinal fica no valor (amount_cents).
 */
enum CashMovementType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Supply = 'supply';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Pagamento',
            self::Refund => 'Estorno',
            self::Supply => 'Suprimento',
            self::Withdrawal => 'Sangria',
        };
    }

    public function isInflow(): bool
    {
        return $this === self::Payment || $this === self::Supply;
    }

    /** Lancado a mao pela equipe (os outros nascem de um pagamento). */
    public function isManual(): bool
    {
        return $this === self::Supply || $this === self::Withdrawal;
    }
}

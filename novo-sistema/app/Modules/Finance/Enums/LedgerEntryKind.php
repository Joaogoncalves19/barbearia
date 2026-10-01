<?php

namespace App\Modules\Finance\Enums;

/**
 * Tipo de lancamento de comissao ou de gorjeta (repasses.md §2). Os dois
 * razoes sao separados; o tipo e o mesmo conceito nos dois.
 */
enum LedgerEntryKind: string
{
    /** Nasce da conclusao do atendimento (comissao do item / gorjeta do pagamento). */
    case Earned = 'earned';

    /** Nasce do estorno de pagamento: lancamento negativo, aponta o estorno. */
    case Refund = 'refund';

    /** Correcao manual, com motivo e autor. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Atendimento',
            self::Refund => 'Estorno',
            self::Adjustment => 'Ajuste',
        };
    }
}

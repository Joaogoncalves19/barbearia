<?php

namespace App\Modules\Finance\Enums;

/** Como a comissao e calculada (comissoes.md §2). */
enum CommissionRuleType: string
{
    /** Percentual sobre a base do item (rate_bp, meio centavo para cima). */
    case Percent = 'percent';

    /** Valor fixo por unidade (amount_cents x quantidade). So para servicos. */
    case Fixed = 'fixed';

    /** Sem comissao (explicito: vence as regras mais gerais). */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentual',
            self::Fixed => 'Valor fixo',
            self::None => 'Sem comissão',
        };
    }
}

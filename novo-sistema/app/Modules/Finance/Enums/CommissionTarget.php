<?php

namespace App\Modules\Finance\Enums;

/** Sobre o que a regra de comissao incide (comissoes.md §2). */
enum CommissionTarget: string
{
    /** Servicos (e combos) do atendimento, depois do desconto. */
    case Service = 'service';

    /** Produtos vendidos no atendimento (sem desconto: desconto nao incide em produto). */
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Serviços',
            self::Product => 'Produtos',
        };
    }
}

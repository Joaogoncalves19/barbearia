<?php

namespace App\Modules\Finance\Enums;

/** Sobre o que a regra de comissao incide (comissoes.md §2). */
enum CommissionTarget: string
{
    /** Servicos (e combos) do atendimento, depois do desconto. */
    case Service = 'service';

    /** Produtos vendidos no atendimento (sem desconto: desconto nao incide em produto). */
    case Product = 'product';

    /**
     * Servico coberto pela assinatura (Fase 9, decisao do dono D-46): sobre o
     * PRECO DE TABELA, mesmo que o cliente pague R$ 0. Percentual, valor fixo
     * por atendimento ou sem comissao; sem regra de assinatura, vale a regra
     * normal do servico sobre o preco de tabela. A mensalidade em si nunca
     * gera comissao.
     */
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Serviços',
            self::Product => 'Produtos',
            self::Subscription => 'Atendimento de assinante',
        };
    }
}

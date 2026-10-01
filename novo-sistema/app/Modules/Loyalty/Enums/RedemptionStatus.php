<?php

namespace App\Modules\Loyalty\Enums;

/**
 * Ciclo de um cupom ou de um resgate de pontos (promocoes.md §4): reservado
 * ao agendar/aplicar, usado na conclusao do atendimento, liberado no
 * cancelamento, na falta ou quando um desconto maior o substitui.
 */
enum RedemptionStatus: string
{
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reservado',
            self::Redeemed => 'Usado',
            self::Released => 'Liberado',
        };
    }
}

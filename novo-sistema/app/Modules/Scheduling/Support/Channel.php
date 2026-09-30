<?php

namespace App\Modules\Scheduling\Support;

/**
 * Por onde vem o pedido. Muda SO quais regras de antecedencia/prazo valem
 * (BookingPolicy); a disponibilidade em si e a mesma para todos.
 */
enum Channel: string
{
    /** Cliente pelo site/conta: antecedencia minima e maxima, prazos de D-13. */
    case Customer = 'customer';

    /** Equipe pelo painel: nao pode no passado; pode encaixar em cima da hora. */
    case Staff = 'staff';
}

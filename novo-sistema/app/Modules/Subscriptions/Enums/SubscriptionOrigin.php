<?php

namespace App\Modules\Subscriptions\Enums;

/** De onde veio a assinatura. */
enum SubscriptionOrigin: string
{
    /** Adesao no agendamento pelo site (como no sistema antigo). */
    case Booking = 'booking';

    /** Link de pagamento gerado pela equipe no painel. */
    case Panel = 'panel';

    /** Importada do sistema antigo. */
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Booking => 'Adesão no agendamento',
            self::Panel => 'Link enviado pela barbearia',
            self::Import => 'Sistema antigo',
        };
    }
}

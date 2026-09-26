<?php

namespace App\Modules\Scheduling\Enums;

enum AppointmentSource: string
{
    case Online = 'online';
    case Staff = 'staff';
    case Chatbot = 'chatbot';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Site',
            self::Staff => 'Equipe',
            self::Chatbot => 'Assistente',
            self::Legacy => 'Sistema antigo',
        };
    }
}

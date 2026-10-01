<?php

namespace App\Modules\Scheduling\Enums;

enum AppointmentSource: string
{
    case Online = 'online';
    case Staff = 'staff';

    /** Cliente chegou sem hora marcada: ocupa a agenda a partir de agora (atendimento.md §2). */
    case WalkIn = 'walk_in';
    case Chatbot = 'chatbot';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Site',
            self::Staff => 'Equipe',
            self::WalkIn => 'Encaixe',
            self::Chatbot => 'Assistente',
            self::Legacy => 'Sistema antigo',
        };
    }
}

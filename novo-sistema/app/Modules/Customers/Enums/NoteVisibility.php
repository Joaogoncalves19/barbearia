<?php

namespace App\Modules\Customers\Enums;

enum NoteVisibility: string
{
    case Team = 'team';
    case Professionals = 'professionals';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'Equipe',
            self::Professionals => 'Profissionais',
        };
    }
}

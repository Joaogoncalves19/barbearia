<?php

namespace App\Modules\LegacyImport\Enums;

enum IssueSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informativo',
            self::Warning => 'Atenção',
            self::Error => 'Erro',
        };
    }
}

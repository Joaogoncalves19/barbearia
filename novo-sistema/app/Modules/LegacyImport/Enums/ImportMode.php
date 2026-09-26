<?php

namespace App\Modules\LegacyImport\Enums;

enum ImportMode: string
{
    case DryRun = 'dry_run';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::DryRun => 'Simulação',
            self::Import => 'Importação',
        };
    }
}

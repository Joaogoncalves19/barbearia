<?php

namespace App\Modules\LegacyImport\Enums;

enum IssueClassification: string
{
    case Valid = 'valid';
    case PotentiallyValid = 'potentially_valid';
    case Inconsistent = 'inconsistent';
    case Duplicate = 'duplicate';
    case Orphan = 'orphan';
    case Legacy = 'legacy';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Válido',
            self::PotentiallyValid => 'Potencialmente válido',
            self::Inconsistent => 'Inconsistente',
            self::Duplicate => 'Duplicado',
            self::Orphan => 'Órfão',
            self::Legacy => 'Legado',
            self::Unknown => 'Desconhecido',
        };
    }
}

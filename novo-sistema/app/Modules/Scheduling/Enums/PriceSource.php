<?php

namespace App\Modules\Scheduling\Enums;

enum PriceSource: string
{
    case CatalogAtBooking = 'catalog_at_booking';
    case Recorded = 'recorded';
    case LegacyCatalogEstimate = 'legacy_catalog_estimate';
    case LegacyUnknown = 'legacy_unknown';

    public function label(): string
    {
        return match ($this) {
            self::CatalogAtBooking => 'Catálogo no agendamento',
            self::Recorded => 'Registrado',
            self::LegacyCatalogEstimate => 'Estimado pelo catálogo antigo',
            self::LegacyUnknown => 'Desconhecido (sistema antigo)',
        };
    }
}

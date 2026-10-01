<?php

namespace App\Modules\Scheduling\Enums;

enum PriceSource: string
{
    case CatalogAtBooking = 'catalog_at_booking';
    case CatalogAtAttendance = 'catalog_at_attendance';
    case Recorded = 'recorded';
    case LegacyCatalogEstimate = 'legacy_catalog_estimate';
    case LegacyUnknown = 'legacy_unknown';

    public function label(): string
    {
        return match ($this) {
            self::CatalogAtBooking => 'Catálogo no agendamento',
            self::CatalogAtAttendance => 'Catálogo no atendimento',
            self::Recorded => 'Registrado',
            self::LegacyCatalogEstimate => 'Estimado pelo catálogo antigo',
            self::LegacyUnknown => 'Desconhecido (sistema antigo)',
        };
    }
}

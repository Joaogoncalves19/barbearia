<?php

namespace App\Modules\LegacyImport\Enums;

enum ImportStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Em andamento',
            self::Completed => 'Concluída',
            self::Failed => 'Falhou',
            self::RolledBack => 'Desfeita (simulação)',
        };
    }
}

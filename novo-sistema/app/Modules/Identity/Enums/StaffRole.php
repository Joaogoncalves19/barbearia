<?php

namespace App\Modules\Identity\Enums;

/**
 * Papeis da equipe. Cada papel e so um NOME: o que ele pode fazer vem da
 * matriz em config/permissions.php, checada pelas Gates (deny by default).
 *
 * Clientes NAO sao usuarios da equipe: terao modelo e guard proprios (Fase 3).
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Reception = 'reception';
    case Finance = 'finance';
    case Professional = 'professional';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Manager => 'Gerente',
            self::Reception => 'Recepção',
            self::Finance => 'Financeiro',
            self::Professional => 'Profissional',
        };
    }
}

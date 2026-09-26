<?php

namespace App\Modules\Scheduling\Enums;

enum ItemType: string
{
    case Service = 'service';
    case Package = 'package';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Serviço',
            self::Package => 'Combo',
            self::Product => 'Produto',
        };
    }
}

<?php

namespace App\Modules\Finance\Enums;

/** Vale (adiantamento) e o seu estorno (repasses.md §4). */
enum AdvanceKind: string
{
    case Advance = 'advance';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Vale',
            self::Reversal => 'Estorno de vale',
        };
    }
}

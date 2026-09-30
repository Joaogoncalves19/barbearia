<?php

namespace App\Modules\Scheduling\Exceptions;

use App\Modules\Scheduling\Services\AvailabilityResult;
use RuntimeException;

/** O horario pedido nao pode ser reservado (nada foi gravado). */
final class SlotUnavailable extends RuntimeException
{
    public function __construct(public readonly AvailabilityResult $result)
    {
        parent::__construct($result->message());
    }

    public function isConflict(): bool
    {
        return $this->result->has('conflict');
    }
}

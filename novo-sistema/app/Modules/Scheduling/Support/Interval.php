<?php

namespace App\Modules\Scheduling\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Intervalo de tempo MEIO-ABERTO [inicio, fim): o fim nao pertence ao
 * intervalo. Por isso atendimentos colados (10:00-10:30 e 10:30-11:00) NAO
 * se sobrepoem, e 10:29-10:59 sobrepoe 10:00-10:30.
 *
 * E a unica regra de sobreposicao e a unica forma de calcular o fim de um
 * atendimento (Interval::starting): nenhuma tela calcula fim por conta propria.
 */
final class Interval
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Intervalo precisa terminar depois de comecar.');
        }
    }

    /** Atendimento que comeca em $start e dura $minutes (regra unica do fim). */
    public static function starting(CarbonImmutable $start, int $minutes): self
    {
        // Duracao nova passa pela regra do catalogo (Duration); aqui so se exige
        // positiva, para remarcar tambem atendimentos antigos (importados).
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Duracao precisa ser positiva.');
        }

        return new self($start->setSecond(0)->setMicrosecond(0), $start->setSecond(0)->setMicrosecond(0)->addMinutes($minutes));
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lt($other->end) && $other->start->lt($this->end);
    }

    /** Este intervalo cabe inteiro dentro de $other? */
    public function within(self $other): bool
    {
        return $this->start->gte($other->start) && $this->end->lte($other->end);
    }

    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }
}

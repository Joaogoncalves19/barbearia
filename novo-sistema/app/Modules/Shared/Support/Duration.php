<?php

namespace App\Modules\Shared\Support;

use InvalidArgumentException;

/**
 * Duracao de atendimento. A unidade do sistema inteiro e MINUTOS INTEIROS
 * (colunas *_minutes), usada direto pela agenda. Este e o unico lugar que
 * conhece os limites e que formata para exibicao ("1 h 15 min").
 */
final class Duration
{
    /** Menor duracao aceita e passo (a grade da agenda e multipla disto). */
    public const STEP_MINUTES = 5;

    /** Maior duracao de um servico (8 horas). */
    public const MAX_MINUTES = 480;

    public static function isValid(int $minutes): bool
    {
        return $minutes >= self::STEP_MINUTES
            && $minutes <= self::MAX_MINUTES
            && $minutes % self::STEP_MINUTES === 0;
    }

    public static function assertValid(int $minutes): void
    {
        if (! self::isValid($minutes)) {
            throw new InvalidArgumentException("Duracao invalida: {$minutes} min.");
        }
    }

    public static function format(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return match (true) {
            $h === 0 => "{$m} min",
            $m === 0 => "{$h} h",
            default => "{$h} h {$m} min",
        };
    }

    /**
     * Opcoes para o seletor de duracao (valor => rotulo).
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $opcoes = [];
        for ($m = self::STEP_MINUTES; $m <= self::MAX_MINUTES; $m += self::STEP_MINUTES) {
            $opcoes[$m] = self::format($m);
        }

        return $opcoes;
    }
}

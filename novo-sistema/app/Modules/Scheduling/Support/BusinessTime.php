<?php

namespace App\Modules\Scheduling\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Politica de fuso horario do sistema (horarios.md, secao "Fuso"):
 *
 * - Banco, PHP e Laravel trabalham em UTC (config app.timezone = UTC).
 *   Instantes (inicio/fim de agendamento, bloqueio) sao gravados em UTC.
 * - A barbearia vive no fuso config('barbearia.display_timezone')
 *   (America/Sao_Paulo). Horas de parede (expediente, funcionamento) e datas
 *   civis (folgas, "o dia 15") sao desse fuso.
 * - A conversao acontece SO aqui: parede -> UTC ao entrar, UTC -> parede ao
 *   exibir. Nenhum outro lugar chama setTimezone()/timezone() para agenda.
 * - Nao depende do fuso da maquina (date_default_timezone_get) nem do
 *   navegador: o navegador so envia data (AAAA-MM-DD) e hora (HH:MM) da
 *   barbearia.
 */
final class BusinessTime
{
    public static function zone(): string
    {
        return (string) config('barbearia.display_timezone', 'America/Sao_Paulo');
    }

    /** Agora, como instante UTC. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /** Hoje no calendario da barbearia (AAAA-MM-DD). */
    public static function today(): string
    {
        return self::now()->setTimezone(self::zone())->toDateString();
    }

    /** Data civil + hora de parede da barbearia -> instante UTC. */
    public static function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.substr($time, 0, 5), self::zone())
            ->setSecond(0)->setMicrosecond(0)->utc();
    }

    /** Instante -> hora local da barbearia (para exibir). */
    public static function local(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::zone());
    }

    /** Data civil (na barbearia) de um instante. */
    public static function dateOf(DateTimeInterface $instant): string
    {
        return self::local($instant)->toDateString();
    }

    /** Dia da semana (0 = domingo ... 6 = sabado) de uma data civil. */
    public static function weekday(string $date): int
    {
        return (int) CarbonImmutable::createFromFormat('Y-m-d', $date, self::zone())->dayOfWeek;
    }

    /** Inicio e fim (UTC) de um dia civil da barbearia. */
    public static function dayBounds(string $date): Interval
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', self::zone());

        return new Interval($inicio->utc(), $inicio->addDay()->utc());
    }

    /** AAAA-MM-DD de verdade (recusa 2026-02-30, 06/10/2026, lixo). */
    public static function isValidDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** HH:MM de verdade (00:00 a 23:59). */
    public static function isValidTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    public static function formatLocal(DateTimeInterface $instant, string $format = 'd/m/Y H:i'): string
    {
        return self::local($instant)->format($format);
    }
}

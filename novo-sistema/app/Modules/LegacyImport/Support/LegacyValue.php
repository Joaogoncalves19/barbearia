<?php

namespace App\Modules\LegacyImport\Support;

use App\Modules\Shared\Support\Decimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Leitura dos valores do banco antigo (quase tudo TEXT, formatos variados).
 *
 * Regra geral: o que nao da para interpretar com seguranca vira null e o
 * chamador registra uma pendencia. Nunca se "chuta" um valor.
 */
final class LegacyValue
{
    public static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /** Texto com possivel escape HTML duplo remanescente (B-15): desfaz um nivel. */
    public static function unescapedText(mixed $v, ?bool &$changed = null): ?string
    {
        $s = self::text($v);
        $changed = false;
        if ($s !== null && preg_match('/&(amp|lt|gt|quot|#0?39|#x27);/', $s)) {
            $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $changed = $decoded !== $s;

            return $decoded;
        }

        return $s;
    }

    /**
     * Dinheiro -> centavos. Aceita o formato que o PHP antigo gravava
     * ("45", "45.5") e o brasileiro ("45,50"). O sistema antigo lia com
     * (float), que interpreta "45,50" como 45: nesse caso $divergent=true.
     *
     * @return array{cents: ?int, rounded: bool, divergent: bool}
     */
    public static function money(mixed $v): array
    {
        $s = self::text($v);
        if ($s === null) {
            return ['cents' => null, 'rounded' => false, 'divergent' => false];
        }

        try {
            $r = Decimal::toScaledInt($s, 2);
        } catch (InvalidArgumentException) {
            return ['cents' => null, 'rounded' => false, 'divergent' => false];
        }

        return ['cents' => $r['value'], 'rounded' => $r['rounded'], 'divergent' => ! is_numeric($s)];
    }

    /** Percentual ("40", "12.5", "12,5%") -> pontos-base; null se invalido/fora de 0..100. */
    public static function percentBp(mixed $v): ?int
    {
        $s = self::text($v);
        if ($s === null) {
            return null;
        }
        try {
            $bp = Decimal::toScaledInt($s, 2)['value'];
        } catch (InvalidArgumentException) {
            return null;
        }

        return ($bp < 0 || $bp > 10000) ? null : $bp;
    }

    public static function int(mixed $v): ?int
    {
        $s = self::text($v);

        return ($s !== null && preg_match('/^-?\d+$/', $s)) ? (int) $s : null;
    }

    public static function bool(mixed $v, bool $default = false): bool
    {
        $s = mb_strtolower((string) self::text($v));
        if ($s === '') {
            return $default;
        }

        return in_array($s, ['1', 'true', 'sim', 's', 'yes', 'on', 'ativo'], true);
    }

    /** Data civil (sem hora): "Y-m-d" ou "d/m/Y" -> "Y-m-d". */
    public static function date(mixed $v): ?string
    {
        $s = self::text($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return ($y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Hora de parede "H:i" / "H:i:s" -> "H:i:s". */
    public static function time(mixed $v): ?string
    {
        $s = self::text($v);
        if ($s === null || ! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            return null;
        }
        [$h, $i, $sec] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        return ($h < 24 && $i < 60 && $sec < 60) ? sprintf('%02d:%02d:%02d', $h, $i, $sec) : null;
    }

    /**
     * Instante local do sistema antigo (America/Sao_Paulo) -> UTC.
     * Aceita "Y-m-d H:i[:s]", "Y-m-dTH:i", "Y-m-d" e timestamp Unix.
     */
    public static function localDateTime(mixed $v, string $tz): ?CarbonImmutable
    {
        $s = self::text($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^\d{9,10}$/', $s)) {
            return CarbonImmutable::createFromTimestampUTC((int) $s);
        }
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}(?::\d{2})?))?/', $s, $m)) {
            return null;
        }
        $data = self::date($m[1]);
        $hora = isset($m[2]) ? self::time($m[2]) : '00:00:00';
        if ($data === null || $hora === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$data} {$hora}", $tz)?->utc();
    }

    /** @return list<string> */
    public static function csv(mixed $v): array
    {
        $s = self::text($v);
        if ($s === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $s)), fn ($x) => $x !== ''));
    }

    /** Hash bcrypt reconhecido ($2y$/$2a$/$2b$), unico formato que o PHP antigo gerava. */
    public static function isBcrypt(?string $hash): bool
    {
        return $hash !== null && preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) === 1;
    }
}

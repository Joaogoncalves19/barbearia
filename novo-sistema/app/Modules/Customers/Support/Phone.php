<?php

namespace App\Modules\Customers\Support;

/**
 * Telefone brasileiro em E.164 (+55DDNNNNNNNN[N]).
 *
 * Aceita mascaras, prefixo 0 de operadora/DDD e +55. Recusa (null) o que
 * nao da para afirmar que e um telefone brasileiro valido: DDD inexistente,
 * tamanho errado, celular sem o 9, sequencia repetida.
 */
final class Phone
{
    private const DDDS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 24, 27, 28, 31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49, 51, 53, 54, 55, 61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79, 81, 82, 83, 84, 85, 86, 87, 88, 89, 91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];

    public static function normalize(?string $telefone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $telefone) ?? '';

        if (strlen($d) >= 12 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        $d = ltrim($d, '0');

        if (! in_array(strlen($d), [10, 11], true)) {
            return null;
        }

        $ddd = (int) substr($d, 0, 2);
        $numero = substr($d, 2);
        if (! in_array($ddd, self::DDDS, true) || preg_match('/^(\d)\1+$/', $numero)) {
            return null;
        }
        // Celular: 9 digitos comecando com 9. Fixo: 8 digitos comecando com 2-5.
        if (strlen($numero) === 9 && $numero[0] !== '9') {
            return null;
        }
        if (strlen($numero) === 8 && ! in_array($numero[0], ['2', '3', '4', '5'], true)) {
            return null;
        }

        return '+55'.$d;
    }
}

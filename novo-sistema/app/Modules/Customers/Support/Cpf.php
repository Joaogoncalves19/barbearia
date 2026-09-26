<?php

namespace App\Modules\Customers\Support;

/** CPF com 11 digitos e digitos verificadores validos; null caso contrario. */
final class Cpf
{
    public static function normalize(?string $cpf): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $cpf) ?? '';
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
            return null;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $d[$i] * (($t + 1) - $i);
            }
            if ((int) $d[$t] !== ((10 * $soma) % 11) % 10) {
                return null;
            }
        }

        return $d;
    }

    /** Para logs e telas: 123.***.***-09. */
    public static function mask(string $cpf): string
    {
        return substr($cpf, 0, 3).'.***.***-'.substr($cpf, -2);
    }
}

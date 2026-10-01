<?php

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Codigo publico curto (ex.: AG-7F3K2Q, AT-9MX4RB): falado ao telefone e
 * usado nas URLs, sem expor a sequencia interna. Sem 0/O e 1/I/L.
 */
final class PublicCode
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @param  class-string<Model>  $model
     */
    public static function generate(string $prefix, string $model, string $column = 'code'): string
    {
        do {
            $code = $prefix.'-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($model::query()->where($column, $code)->exists());

        return $code;
    }
}

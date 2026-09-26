<?php

namespace App\Modules\Customers\Support;

final class Email
{
    /** Minusculo e sem espacos; null se vazio ou invalido. */
    public static function normalize(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || strlen($email) > 254) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}

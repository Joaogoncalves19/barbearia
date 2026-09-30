<?php

namespace App\Modules\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Limite em BYTES (nao em caracteres). Usado na senha: o bcrypt ignora o
 * que passa de 72 bytes, e letras acentuadas ou emoji ocupam mais de 1 byte.
 */
final class MaxBytes implements ValidationRule
{
    public function __construct(private readonly int $bytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->bytes) {
            $fail('A senha é longa demais. Use uma senha mais curta (até cerca de 60 caracteres).');
        }
    }
}

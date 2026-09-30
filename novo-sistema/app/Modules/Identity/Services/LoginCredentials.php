<?php

namespace App\Modules\Identity\Services;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\Email;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Monta as credenciais de busca (sem a senha) de cada tipo de conta. A
 * busca ja filtra quem nao pode entrar, entao conta inativa se comporta
 * exatamente como conta inexistente.
 */
final class LoginCredentials
{
    /**
     * Equipe: nome de usuario OU e-mail no mesmo campo. Tem "@" => e-mail.
     *
     * @return array<string, mixed>
     */
    public static function staff(string $identifier): array
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@')
            ? ['email' => User::normalizeEmail($identifier), 'is_active' => true]
            : ['username' => User::normalizeUsername($identifier), 'is_active' => true];
    }

    /**
     * Equipe, para redefinicao por e-mail: so conta ativa COM e-mail.
     *
     * @return array<string, mixed>
     */
    public static function staffWithEmail(string $identifier): array
    {
        return self::staff($identifier) + [
            'has_email' => fn (Builder $q) => $q->whereNotNull('email'),
        ];
    }

    /**
     * Cliente: e-mail. Excluido (soft delete) ja fica de fora pelo escopo.
     *
     * @return array<string, mixed>
     */
    public static function customer(string $email): array
    {
        return [
            'email' => Email::normalize($email) ?? '',
            'eligible' => self::eligibleCustomer(),
        ];
    }

    /** Cliente apto a entrar com este e-mail (mesma busca do login). */
    public static function findCustomer(string $email): ?Customer
    {
        $encontrado = Auth::createUserProvider('customers')?->retrieveByCredentials(self::customer($email));

        return $encontrado instanceof Customer ? $encontrado : null;
    }

    /**
     * @return Closure(Builder<Customer>): void
     */
    public static function eligibleCustomer(): Closure
    {
        return function (Builder $q): void {
            $q->where('status', CustomerStatus::Active->value)
                ->whereNull('merged_into_customer_id')
                ->whereNull('anonymized_at');
        };
    }
}

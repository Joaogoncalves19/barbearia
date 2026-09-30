<?php

namespace App\Modules\Identity\Authorization;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;

/**
 * Le a matriz de config/permissions.php e responde "este ator pode?".
 *
 * Nega por padrao em todos os caminhos: ator desconhecido, inativo, sem
 * papel, papel sem entrada na matriz, habilidade nao declarada ou declarada
 * para o outro tipo de conta (equipe x cliente).
 */
final class PermissionMatrix
{
    /**
     * @return array<string, string>
     */
    public static function staffAbilities(): array
    {
        return (array) config('permissions.abilities', []);
    }

    /**
     * @return array<string, string>
     */
    public static function customerAbilities(): array
    {
        return (array) config('permissions.customer_abilities', []);
    }

    /**
     * Habilidades listadas para o papel (ja filtradas pelas declaradas).
     *
     * @return list<string>
     */
    public static function abilitiesForRole(string $role): array
    {
        $listadas = config('permissions.roles.'.$role);

        if (! is_array($listadas)) {
            return [];
        }

        return array_values(array_filter(
            $listadas,
            fn ($h) => is_string($h) && array_key_exists($h, self::staffAbilities()),
        ));
    }

    public static function allows(mixed $actor, string $ability): bool
    {
        return match (true) {
            $actor instanceof User => self::staffAllows($actor, $ability),
            $actor instanceof Customer => self::customerAllows($actor, $ability),
            default => false,
        };
    }

    public static function staffAllows(User $user, string $ability): bool
    {
        if (! $user->is_active || $user->role === null) {
            return false;
        }

        return in_array($ability, self::abilitiesForRole($user->role->value), true);
    }

    public static function customerAllows(Customer $customer, string $ability): bool
    {
        return $customer->canSignIn() && array_key_exists($ability, self::customerAbilities());
    }
}

<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;

/**
 * Contas da equipe. Quem gerencia: so quem tem users.manage (proprietario).
 *
 * Ninguem altera o PROPRIO papel, desativa a si mesmo ou define senha
 * provisoria para si (a propria senha muda pela tela "Minha conta"): evita
 * tanto a escalada de privilegio quanto trancar-se para fora.
 *
 * O ator pode ser um cliente (outro guard): sempre negado, nunca erro.
 */
class UserPolicy
{
    public function viewAny(User|Customer $actor): bool
    {
        return $this->manages($actor);
    }

    public function create(User|Customer $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User|Customer $actor, User $target): bool
    {
        return $this->manages($actor);
    }

    public function changeRoleOrStatus(User|Customer $actor, User $target): bool
    {
        return $this->manages($actor) && ! $actor->is($target);
    }

    public function setTemporaryPassword(User|Customer $actor, User $target): bool
    {
        return $this->manages($actor) && ! $actor->is($target);
    }

    private function manages(User|Customer $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('users.manage');
    }
}

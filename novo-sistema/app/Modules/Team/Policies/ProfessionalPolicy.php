<?php

namespace App\Modules\Team\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Illuminate\Auth\Access\Response;

/**
 * Ficha do profissional. Quem ve a equipe (team.view) ve qualquer ficha; o
 * profissional ve SO a propria. Ficha de outro responde 404 (nao confirma
 * nem que o id existe). Cliente (outro guard): negado.
 */
class ProfessionalPolicy
{
    public function view(User|Customer $actor, Professional $professional): Response
    {
        if (! $actor instanceof User) {
            return Response::denyAsNotFound();
        }

        if ($actor->hasPermission('team.view')) {
            return Response::allow();
        }

        return $actor->hasPermission('panel.access') && $this->isOwn($actor, $professional)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User|Customer $actor, Professional $professional): bool
    {
        return $actor instanceof User && $actor->hasPermission('team.manage');
    }

    private function isOwn(User $actor, Professional $professional): bool
    {
        return $professional->user_id !== null && $professional->user_id === $actor->id;
    }
}

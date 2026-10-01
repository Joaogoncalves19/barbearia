<?php

namespace App\Modules\Team\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Illuminate\Auth\Access\Response;

/**
 * Ficha do profissional. Quem tem professionals.view ve qualquer ficha; o
 * profissional ve SO a propria. Ficha de outro responde 404 (nao confirma
 * nem que o id existe). Cliente (outro guard): negado.
 *
 * Alterar e sempre por capacidade (professionals.*): o profissional NAO
 * edita a propria ficha (preco, servicos e apresentacao sao da gestao).
 */
class ProfessionalPolicy
{
    public function view(User|Customer $actor, Professional $professional): Response
    {
        if (! $actor instanceof User) {
            return Response::denyAsNotFound();
        }

        if ($actor->hasPermission('professionals.view')) {
            return Response::allow();
        }

        return $actor->hasPermission('panel.access') && $this->isOwn($actor, $professional)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Extrato do profissional (comissao, gorjeta, vales, repasses; Fase 7).
     * commissions.view: qualquer um; commissions.view_own: so o proprio.
     * Extrato de outro: 404.
     */
    public function viewLedger(User|Customer $actor, Professional $professional): Response
    {
        if (! $actor instanceof User) {
            return Response::denyAsNotFound();
        }
        if ($actor->hasPermission('commissions.view')) {
            return Response::allow();
        }

        return $actor->hasPermission('commissions.view_own') && $this->isOwn($actor, $professional)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User|Customer $actor, Professional $professional): bool
    {
        return $actor instanceof User && $actor->hasPermission('professionals.update');
    }

    private function isOwn(User $actor, Professional $professional): bool
    {
        return $professional->user_id !== null && $professional->user_id === $actor->id;
    }
}

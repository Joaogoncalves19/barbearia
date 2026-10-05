<?php

namespace App\Modules\Reviews\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;

/**
 * Avaliacoes no painel (avaliacoes.md §3): a lista abre para quem ve todas
 * (reviews.view) ou para o profissional com ficha vinculada
 * (reviews.view_own; o controller filtra as dele e so as publicadas).
 * Cliente nunca usa esta lista (a dele e a da conta).
 */
class ReviewPolicy
{
    public function viewAny(User|Customer $actor): bool
    {
        return $actor instanceof User
            && ($actor->hasPermission('reviews.view') || ($actor->hasPermission('reviews.view_own') && $actor->professional !== null));
    }
}

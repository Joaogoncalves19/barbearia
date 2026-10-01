<?php

namespace App\Modules\Finance\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Repasse: payouts.view ve qualquer um; o profissional (commissions.view_own)
 * ve so os proprios. De outro = 404 (nao confirma que existe).
 */
class CommissionPayoutPolicy
{
    public function view(User|Customer $actor, CommissionPayout $payout): Response
    {
        if (! $actor instanceof User) {
            return Response::denyAsNotFound();
        }
        if ($actor->hasPermission('payouts.view')) {
            return Response::allow();
        }
        $dono = $payout->professional?->user_id;

        return $actor->hasPermission('commissions.view_own') && $dono !== null && $dono === $actor->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}

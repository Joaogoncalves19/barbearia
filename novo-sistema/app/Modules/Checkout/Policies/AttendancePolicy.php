<?php

namespace App\Modules\Checkout\Policies;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Illuminate\Auth\Access\Response;

/**
 * QUEM pode fazer o que com um atendimento (acesso horizontal). As regras de
 * estado, valores e estoque ficam no AttendanceService.
 *
 * - Cliente: so os proprios atendimentos concluidos (comprovante). Alheio
 *   ou nao concluido => 404.
 * - Equipe: attendances.view/manage valem para todos; o profissional
 *   (view_own/manage_own) so para os atendimentos em que ele e o
 *   profissional responsavel. Alheio => 404 (nao confirma que existe).
 */
class AttendancePolicy
{
    public function view(User|Customer $actor, Attendance $attendance): Response
    {
        if ($actor instanceof Customer) {
            return $actor->canSignIn() && $attendance->customer_id === $actor->id && $attendance->status === AttendanceStatus::Completed
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        if ($actor->hasPermission('attendances.view')) {
            return Response::allow();
        }

        return $actor->hasPermission('attendances.view_own') && $this->isOwn($actor, $attendance)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Ver a lista de atendimentos (todos ou so os proprios). */
    public function viewAny(User|Customer $actor): bool
    {
        return $actor instanceof User && ($actor->hasPermission('attendances.view') || $actor->hasPermission('attendances.view_own'));
    }

    /** Abrir atendimento sem agendamento (encaixe). */
    public function create(User|Customer $actor): bool
    {
        return $actor instanceof User && ($actor->hasPermission('attendances.manage') || $actor->hasPermission('attendances.manage_own'));
    }

    /** Abrir atendimento (ou passar o atendimento) para este profissional. */
    public function openFor(User|Customer $actor, Professional $professional): bool
    {
        return $actor instanceof User && (
            $actor->hasPermission('attendances.manage')
            || ($actor->hasPermission('attendances.manage_own') && $professional->user_id !== null && $professional->user_id === $actor->id)
        );
    }

    /** Iniciar, itens, consumo, profissional, observacoes. */
    public function update(User|Customer $actor, Attendance $attendance): bool
    {
        return $actor instanceof User && (
            $actor->hasPermission('attendances.manage')
            || ($actor->hasPermission('attendances.manage_own') && $this->isOwn($actor, $attendance))
        );
    }

    /** Concluir registra pagamento: exige tambem payments.receive. */
    public function complete(User|Customer $actor, Attendance $attendance): bool
    {
        return $actor instanceof User && $this->update($actor, $attendance) && $actor->hasPermission('payments.receive');
    }

    public function discount(User|Customer $actor, Attendance $attendance): bool
    {
        return $actor instanceof User && $this->update($actor, $attendance) && $actor->hasPermission('attendances.discount');
    }

    public function cancel(User|Customer $actor, Attendance $attendance): bool
    {
        return $actor instanceof User && (
            $actor->hasPermission('attendances.cancel')
            || ($actor->hasPermission('attendances.manage_own') && $this->isOwn($actor, $attendance))
        );
    }

    private function isOwn(User $actor, Attendance $attendance): bool
    {
        $professional = $attendance->professional;

        return $professional !== null && $professional->user_id !== null && $professional->user_id === $actor->id;
    }
}

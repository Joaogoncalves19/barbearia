<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Auth\Access\Response;

/**
 * Agendamento (a agenda em si e da Fase 5; aqui fica a regra de quem ve e
 * quem mexe, para as proximas fases so usarem).
 *
 * - Cliente: so os proprios. Alheio => 404. Remarcar/cancelar pelo cliente
 *   depende da politica de cancelamento (D-13, Fase 5): negado por enquanto.
 * - Equipe: *_all ve/mexe em todos; profissional (*_own) so na propria agenda.
 */
class AppointmentPolicy
{
    public function view(User|Customer $actor, Appointment $appointment): Response
    {
        if ($actor instanceof Customer) {
            return $actor->canSignIn() && $appointment->customer_id === $actor->id
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        if ($actor->hasPermission('appointments.view_all')) {
            return Response::allow();
        }

        return $actor->hasPermission('appointments.view_own') && $this->isOwnAgenda($actor, $appointment)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User|Customer $actor, Appointment $appointment): bool
    {
        if ($actor instanceof Customer) {
            return false;
        }

        return $actor->hasPermission('appointments.manage')
            || ($actor->hasPermission('appointments.manage_own') && $this->isOwnAgenda($actor, $appointment));
    }

    public function cancel(User|Customer $actor, Appointment $appointment): bool
    {
        if ($actor instanceof Customer) {
            return false;
        }

        return $actor->hasPermission('appointments.cancel')
            || ($actor->hasPermission('appointments.manage_own') && $this->isOwnAgenda($actor, $appointment));
    }

    private function isOwnAgenda(User $actor, Appointment $appointment): bool
    {
        $professional = $appointment->professional;

        return $professional !== null && $professional->user_id !== null && $professional->user_id === $actor->id;
    }
}

<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;

/**
 * QUEM pode fazer o que com um agendamento. As regras de prazo, limite e
 * disponibilidade ficam no BookingService (mensagem especifica ao usuario);
 * aqui so a autorizacao.
 *
 * - Cliente: so os proprios. Alheio => 404. Remarca/cancela os proprios que
 *   ainda nao aconteceram (o prazo e conferido no BookingService).
 * - Equipe: appointments.view_all/manage/cancel valem para todos;
 *   profissional (view_own/manage_own) so na propria agenda.
 */
class AppointmentPolicy
{
    public function view(User|Customer $actor, Appointment $appointment): Response
    {
        if ($actor instanceof Customer) {
            return $this->isOwnCustomer($actor, $appointment) ? Response::allow() : Response::denyAsNotFound();
        }

        if ($actor->hasPermission('appointments.view_all')) {
            return Response::allow();
        }

        return $actor->hasPermission('appointments.view_own') && $this->isOwnAgenda($actor, $appointment)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Criar na agenda deste profissional (painel). */
    public function createFor(User|Customer $actor, Professional $professional): bool
    {
        return $actor instanceof User && (
            $actor->hasPermission('appointments.manage')
            || ($actor->hasPermission('appointments.manage_own') && $professional->user_id === $actor->id)
        );
    }

    /** Editar observacoes, confirmar, marcar falta. */
    public function update(User|Customer $actor, Appointment $appointment): bool
    {
        if ($actor instanceof Customer) {
            return false;
        }

        return $actor->hasPermission('appointments.manage')
            || ($actor->hasPermission('appointments.manage_own') && $this->isOwnAgenda($actor, $appointment));
    }

    public function reschedule(User|Customer $actor, Appointment $appointment): Response
    {
        if ($actor instanceof Customer) {
            return $this->customerChange($actor, $appointment);
        }

        return $this->update($actor, $appointment) ? Response::allow() : Response::deny();
    }

    public function cancel(User|Customer $actor, Appointment $appointment): Response
    {
        if ($actor instanceof Customer) {
            return $this->customerChange($actor, $appointment);
        }

        return $actor->hasPermission('appointments.cancel')
            || ($actor->hasPermission('appointments.manage_own') && $this->isOwnAgenda($actor, $appointment))
            ? Response::allow()
            : Response::deny();
    }

    /** Cliente: agendamento alheio = 404 (nao confirma que existe); proprio ja passado = 403. */
    private function customerChange(Customer $actor, Appointment $appointment): Response
    {
        if (! $this->isOwnCustomer($actor, $appointment)) {
            return Response::denyAsNotFound();
        }

        return $this->customerCanChange($appointment) ? Response::allow() : Response::deny();
    }

    private function isOwnCustomer(Customer $actor, Appointment $appointment): bool
    {
        return $actor->canSignIn() && $appointment->customer_id === $actor->id;
    }

    /** Ainda nao aconteceu e esta aberto (pendente/confirmado). */
    private function customerCanChange(Appointment $appointment): bool
    {
        return $appointment->status->isOpen()
            && $appointment->starts_at !== null
            && CarbonImmutable::instance($appointment->starts_at)->gt(BusinessTime::now());
    }

    private function isOwnAgenda(User $actor, Appointment $appointment): bool
    {
        $professional = $appointment->professional;

        return $professional !== null && $professional->user_id !== null && $professional->user_id === $actor->id;
    }
}

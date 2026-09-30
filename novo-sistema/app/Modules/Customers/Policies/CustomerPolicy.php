<?php

namespace App\Modules\Customers\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Auth\Access\Response;

/**
 * Cadastro do cliente.
 *
 * - Cliente: so o proprio cadastro. Outro cliente => 404.
 * - Equipe: customers.view ve todos; customers.view_own (profissional) ve
 *   so quem tem agendamento com ele. Sem isso => 404.
 */
class CustomerPolicy
{
    public function view(User|Customer $actor, Customer $customer): Response
    {
        if ($actor instanceof Customer) {
            return $this->self($actor, $customer);
        }

        if ($actor->hasPermission('customers.view')) {
            return Response::allow();
        }

        return $actor->hasPermission('customers.view_own') && $this->isClientOf($actor, $customer)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User|Customer $actor, Customer $customer): Response
    {
        if ($actor instanceof Customer) {
            return $this->self($actor, $customer);
        }

        return $actor->hasPermission('customers.update') ? Response::allow() : Response::deny();
    }

    /** CPF completo (fora disso, so mascarado). */
    public function viewCpf(User|Customer $actor, Customer $customer): bool
    {
        if ($actor instanceof Customer) {
            return $actor->is($customer);
        }

        return $actor->hasPermission('customers.view_cpf');
    }

    public function anonymize(User|Customer $actor, Customer $customer): bool
    {
        return $actor instanceof User && $actor->hasPermission('customers.anonymize');
    }

    private function self(Customer $actor, Customer $customer): Response
    {
        return $actor->is($customer) && $actor->canSignIn() ? Response::allow() : Response::denyAsNotFound();
    }

    private function isClientOf(User $actor, Customer $customer): bool
    {
        return Appointment::query()
            ->where('customer_id', $customer->id)
            ->whereHas('professional', fn ($q) => $q->where('user_id', $actor->id))
            ->exists();
    }
}

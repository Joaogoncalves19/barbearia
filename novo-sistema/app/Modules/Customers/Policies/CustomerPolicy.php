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

        if (! $actor->hasPermission('customers.update')) {
            return Response::deny();
        }

        // Tela Clientes (P13-01): cadastro anonimizado ou mesclado em outro nao
        // volta a ter dados pela equipe.
        return $customer->anonymized_at === null && $customer->merged_into_customer_id === null
            ? Response::allow()
            : Response::deny('Este cadastro foi anonimizado ou mesclado e não pode ser editado.');
    }

    /**
     * Anotacoes dos profissionais sobre o cliente (Fase 12.5): so quem tem
     * customers.notes_own e so dos proprios clientes. Outro cliente => 404.
     */
    public function notes(User|Customer $actor, Customer $customer): Response
    {
        return $actor instanceof User && $actor->hasPermission('customers.notes_own') && $this->isClientOf($actor, $customer)
            ? Response::allow()
            : Response::denyAsNotFound();
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
        return $actor instanceof User && $actor->hasPermission('customers.anonymize')
            && $customer->anonymized_at === null && $customer->merged_into_customer_id === null;
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

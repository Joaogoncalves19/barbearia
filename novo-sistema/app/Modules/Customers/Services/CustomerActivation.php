<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Ativar e desativar o cadastro do cliente pela equipe (Fase 13, decisao do
 * dono). A situacao fica fora do $fillable do model: muda so por aqui.
 *
 * Desativar nao apaga nada (historico, pontos, agendamentos ficam). O efeito e
 * o que o sistema ja fazia com cliente inativo: nao entra na conta (as sessoes
 * abertas caem na proxima requisicao, customer.active) e sai da busca do
 * balcao. Cadastro anonimizado ou mesclado nao muda (CustomerPolicy). A
 * auditoria registra pelo model (Auditable), com quem esta logado.
 */
final class CustomerActivation
{
    public function setActive(Customer $customer, bool $active): void
    {
        DB::transaction(function () use ($customer, $active): void {
            /** @var Customer $c */
            $c = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($c->anonymized_at !== null || $c->merged_into_customer_id !== null) {
                throw DomainRuleViolation::rule('R-33', 'Cadastro anonimizado ou mesclado não muda de situação.');
            }

            $c->status = $active ? CustomerStatus::Active : CustomerStatus::Inactive;
            $c->save();
        });
    }
}

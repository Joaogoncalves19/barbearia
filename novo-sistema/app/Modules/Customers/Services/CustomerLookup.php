<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Busca de cliente no balcao (agendar, abrir atendimento): so para quem pode
 * ver clientes; por nome, e-mail ou telefone; so cadastros ativos.
 */
final class CustomerLookup
{
    /**
     * @return Collection<int, Customer>
     */
    public function search(User $user, string $term): Collection
    {
        if (mb_strlen($term) < 2 || ! $user->can('customers.view')) {
            return collect();
        }

        $digitos = preg_replace('/\D+/', '', $term);

        return Customer::query()
            ->where('status', 'active')->whereNull('merged_into_customer_id')->whereNull('anonymized_at')
            ->where(function ($q) use ($term, $digitos) {
                $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.mb_strtolower($term).'%');
                if ($digitos !== null && strlen($digitos) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digitos.'%');
                }
            })
            ->orderBy('name')->limit(10)->get();
    }
}

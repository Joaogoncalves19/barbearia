<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Email;
use App\Modules\Customers\Support\Phone;

/**
 * Encontra clientes existentes que colidem com os dados informados, pelos
 * campos normalizados. So DETECTA: mesclar e decisao humana
 * (estrategia-duplicidades.md).
 */
class DuplicateCustomerFinder
{
    /**
     * @return array<string, int> campo => id do cliente que ja usa o valor
     */
    public function conflicts(?string $email, ?string $phone, ?string $cpf, ?int $ignoreId = null): array
    {
        $valores = array_filter([
            'email' => Email::normalize($email),
            'phone' => Phone::normalize($phone),
            'cpf' => Cpf::normalize($cpf),
        ]);

        $achados = [];
        foreach ($valores as $campo => $valor) {
            $id = Customer::withTrashed()->where($campo, $valor)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->value('id');
            if ($id !== null) {
                $achados[$campo] = (int) $id;
            }
        }

        return $achados;
    }
}

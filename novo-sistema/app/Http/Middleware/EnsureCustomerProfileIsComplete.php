<?php

namespace App\Http\Middleware;

use App\Modules\Customers\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CPF e obrigatorio para o cliente (decisao da Fase 3). Cliente sem CPF
 * (vindo do importador ou de cadastro incompleto) informa o CPF antes de
 * usar qualquer outra tela da conta.
 */
class EnsureCustomerProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');

        if ($customer instanceof Customer && $customer->needsProfileCompletion()) {
            return redirect()->route('account.complete.edit');
        }

        return $next($request);
    }
}

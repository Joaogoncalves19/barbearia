<?php

namespace App\Http\Middleware;

use App\Modules\Customers\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revalida o cliente a cada requisicao da area do cliente: desativado,
 * mesclado em outro cadastro, anonimizado ou excluido => sessao encerrada.
 */
class EnsureCustomerIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer || ! $customer->canSignIn()) {
            Log::channel('security')->notice('Sessao de cliente encerrada: conta inativa ou invalida', [
                'customer_id' => $customer?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ]);

            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('customer.login')
                ->withErrors(['email' => 'Não foi possível acessar esta conta. Fale com a barbearia.']);
        }

        return $next($request);
    }
}

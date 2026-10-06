<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerRecentlyConfirmed;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Confirme sua senha" antes de uma acao sensivel da conta (middleware
 * customer.reauth). Limite de tentativas por conta + IP (password-check).
 */
class ConfirmPasswordController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.confirm-password', ['hasPassword' => $this->customer($request)->hasPassword()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cliente = $this->customer($request);
        abort_unless($cliente->hasPassword(), 404);
        $request->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => 'senha']);

        if (! Hash::check($request->string('password')->value(), $cliente->getAuthPassword())) {
            Log::channel('security')->notice('Confirmação de senha do cliente recusada', ['customer_id' => $cliente->id, 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['password' => 'A senha não confere.']);
        }

        EnsureCustomerRecentlyConfirmed::markConfirmed($request);

        return redirect()->intended(route('account.privacy'));
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}

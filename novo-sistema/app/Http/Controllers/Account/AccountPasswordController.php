<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Services\PasswordManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Troca de senha do cliente. Quem ja tem senha informa a atual. Quem entra
 * so por link magico (sem senha) pode criar uma: ja provou o e-mail ao
 * entrar pelo link. As outras sessoes caem (auth.session).
 */
class AccountPasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.password', ['hasPassword' => $this->customer($request)->hasPassword()]);
    }

    public function update(Request $request, PasswordManager $passwords): RedirectResponse
    {
        $customer = $this->customer($request);
        $temSenha = $customer->hasPassword();

        $request->validate([
            'current_password' => $temSenha ? ['required', 'string', 'max:255'] : ['prohibited'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [], ['current_password' => 'senha atual', 'password' => 'nova senha']);

        if ($temSenha && ! Hash::check($request->string('current_password')->value(), $customer->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
        }

        $passwords->change($customer, $request->string('password')->value());
        $request->session()->regenerate();

        return redirect()->route('account.password.edit')->with('status', 'Senha alterada. Os outros aparelhos conectados foram desconectados.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}

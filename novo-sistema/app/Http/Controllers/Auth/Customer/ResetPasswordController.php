<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Services\LoginCredentials;
use App\Modules\Identity\Services\PasswordManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Redefinicao pelo link do e-mail (tambem serve para quem nunca teve senha,
 * como cliente cadastrado no balcao ou importado sem senha reconhecida).
 */
class ResetPasswordController extends Controller
{
    public function create(string $token): View
    {
        return view('auth.customer.reset-password', ['token' => $token]);
    }

    public function store(ResetPasswordRequest $request, PasswordManager $passwords): RedirectResponse
    {
        $status = Password::broker('customers')->reset(
            LoginCredentials::customer($request->string('email')->value()) + [
                'password' => $request->string('password')->value(),
                'password_confirmation' => $request->string('password_confirmation')->value(),
                'token' => $request->string('token')->value(),
            ],
            fn (Customer $customer, string $password) => $passwords->resetByLink($customer, $password),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Este link de redefinição não é válido ou já expirou. Peça um novo.',
            ]);
        }

        return redirect()->route('customer.login')->with('status', 'Senha alterada. Entre com a nova senha.');
    }
}

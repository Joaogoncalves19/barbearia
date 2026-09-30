<?php

namespace App\Http\Controllers\Auth\Customer;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LoginCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/** "Esqueci a senha" do cliente. Resposta identica exista ou nao a conta. */
class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.customer.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [], ['email' => 'e-mail']);

        Password::broker('customers')->sendResetLink(LoginCredentials::customer($request->string('email')->value()));

        return back()->with('status', 'Se este e-mail tiver cadastro, enviamos um link para criar uma nova senha. Confira sua caixa de entrada.');
    }
}

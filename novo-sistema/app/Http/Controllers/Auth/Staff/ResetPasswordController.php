<?php

namespace App\Http\Controllers\Auth\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Redefinicao pelo link do e-mail. O token so vale uma vez, expira em 60
 * min e fica guardado como hash. Nao faz login: a pessoa entra com a senha
 * nova (as outras sessoes dela caem).
 */
class ResetPasswordController extends Controller
{
    public function create(string $token): View
    {
        return view('auth.staff.reset-password', ['token' => $token]);
    }

    public function store(ResetPasswordRequest $request, PasswordManager $passwords): RedirectResponse
    {
        $status = Password::broker('users')->reset(
            [
                'email' => User::normalizeEmail($request->string('email')->value()),
                'is_active' => true,
                'password' => $request->string('password')->value(),
                'password_confirmation' => $request->string('password_confirmation')->value(),
                'token' => $request->string('token')->value(),
            ],
            fn (User $user, string $password) => $passwords->resetByLink($user, $password),
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Token errado, vencido, ja usado ou e-mail que nao confere: mesma
            // mensagem (nao confirma se o e-mail existe).
            throw ValidationException::withMessages([
                'email' => 'Este link de redefinição não é válido ou já expirou. Peça um novo.',
            ]);
        }

        return redirect()->route('staff.login')->with('status', 'Senha alterada. Entre com a nova senha.');
    }
}

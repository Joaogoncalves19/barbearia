<?php

namespace App\Http\Controllers\Auth\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Reconfirmar a senha antes de acoes sensiveis (gestao de usuarios). Vale
 * por auth.password_timeout (15 min). Protege contra quem pega um painel
 * aberto e desbloqueado.
 */
class ConfirmPasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.staff.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => 'senha']);

        $hash = $request->user('web')?->getAuthPassword();

        if (! is_string($hash) || ! Hash::check($request->string('password')->value(), $hash)) {
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('panel.home'));
    }
}

<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Troca da propria senha (inclusive a troca obrigatoria da senha
 * provisoria). Exige a senha atual; a nova precisa ser diferente. As outras
 * sessoes da pessoa caem (auth.session).
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('panel.password', ['mustChange' => (bool) $this->user($request)->must_change_password]);
    }

    public function update(Request $request, PasswordManager $passwords): RedirectResponse
    {
        $user = $this->user($request);

        $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ], ['password.different' => 'A nova senha precisa ser diferente da atual.'], ['current_password' => 'senha atual', 'password' => 'nova senha']);

        if (! Hash::check($request->string('current_password')->value(), (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
        }

        $passwords->change($user, $request->string('password')->value());
        $request->session()->regenerate();

        return redirect()->route('panel.home')->with('status', 'Senha alterada. Os outros aparelhos conectados foram desconectados.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

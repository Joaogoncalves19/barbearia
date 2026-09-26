<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login minimo da EQUIPE, criado na fundacao para exercitar autenticacao e
 * autorizacao. Recuperacao de senha, gestao de usuarios e login de clientes
 * pertencem a Fase 3.
 */
class StaffLoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(StaffLoginRequest $request): RedirectResponse
    {
        $credenciais = [
            'email' => mb_strtolower($request->string('email')->trim()->value()),
            'password' => $request->string('password')->value(),
            'is_active' => true,
        ];

        if (! Auth::attempt($credenciais, $request->boolean('remember'))) {
            Log::channel('security')->notice('Login da equipe recusado', [
                'email_hash' => hash('sha256', $credenciais['email']),
                'ip' => $request->ip(),
            ]);

            // Mesma mensagem para e-mail inexistente, senha errada ou conta
            // inativa: a resposta nao pode revelar qual conta existe.
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('panel.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

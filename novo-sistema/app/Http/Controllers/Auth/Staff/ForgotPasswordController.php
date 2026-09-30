<?php

namespace App\Http\Controllers\Auth\Staff;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LoginCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Esqueci a senha" da equipe. Aceita usuario ou e-mail; o link vai para o
 * e-mail cadastrado. Quem nao tem e-mail pede ao proprietario uma senha
 * provisoria (a tela explica isso para todos, sem revelar quem tem e-mail).
 */
class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.staff.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['identifier' => ['required', 'string', 'max:255']], [], ['identifier' => 'usuário ou e-mail']);

        // Conta inexistente, inativa, sem e-mail ou pedido repetido em menos
        // de 1 minuto: nada e enviado, e a resposta e identica (o broker
        // tambem iguala o tempo de resposta).
        Password::broker('users')->sendResetLink(
            LoginCredentials::staffWithEmail($request->string('identifier')->value())
        );

        return back()->with('status', 'Se houver uma conta ativa com e-mail cadastrado, enviamos um link para criar uma nova senha. Confira sua caixa de entrada.');
    }
}

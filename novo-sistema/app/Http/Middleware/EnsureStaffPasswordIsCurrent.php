<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Senha provisoria (definida pelo proprietario) precisa ser trocada antes
 * de qualquer outra coisa no painel. Libera so a tela de troca de senha.
 */
class EnsureStaffPasswordIsCurrent
{
    private const ALLOWED = ['panel.password.edit', 'panel.password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED)) {
            return redirect()->route('panel.password.edit')
                ->with('status', 'Crie uma senha pessoal para continuar. A senha atual é provisória.');
        }

        return $next($request);
    }
}

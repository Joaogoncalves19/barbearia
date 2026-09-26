<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revalida o usuario da equipe a CADA requisicao do painel.
 *
 * O sistema antigo so olhava a sessao: um usuario excluido ou rebaixado
 * continuava entrando (e ate virava proprietario) ate a sessao expirar
 * (achado S-09). Aqui, desativou => perde o acesso na hora.
 */
class EnsureStaffIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            Log::channel('security')->notice('Sessao encerrada: usuario inativo ou invalido', [
                'user_id' => $user?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Seu acesso foi desativado. Fale com o proprietário.']);
        }

        return $next($request);
    }
}

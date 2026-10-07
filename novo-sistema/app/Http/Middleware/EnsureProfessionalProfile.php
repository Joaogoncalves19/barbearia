<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Area do profissional (Fase 12.5): alem da habilidade, o usuario precisa
 * estar ligado a uma ficha de profissional. Sem ficha nao ha agenda,
 * atendimento nem extrato "proprios" para mostrar: 403.
 */
class EnsureProfessionalProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->professional !== null, 403, 'Seu usuário não está ligado a uma ficha de profissional. Fale com a gerência.');

        return $next($request);
    }
}

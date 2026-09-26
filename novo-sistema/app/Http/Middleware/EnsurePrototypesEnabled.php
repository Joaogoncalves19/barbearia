<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * As paginas de referencia visual so existem onde foram ligadas
 * explicitamente (BARBEARIA_PROTOTYPES=true). Em producao respondem 404,
 * como se nao existissem.
 */
class EnsurePrototypesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('barbearia.prototypes_enabled'), 404);

        return $next($request);
    }
}

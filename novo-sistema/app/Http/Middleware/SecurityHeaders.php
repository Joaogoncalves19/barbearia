<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabecalhos de seguranca de todas as respostas web.
 *
 * CSP estrita: sem 'unsafe-inline' e sem 'unsafe-eval'. Scripts e estilos so
 * do proprio dominio (assets do Vite) ou com o nonce desta requisicao. Por
 * isso o frontend usa o build CSP do Alpine e nao usa atributos style=""
 * nem handlers onclick="" (ver design-system.md).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->csp($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function csp(string $nonce): string
    {
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $styleSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];

        // Servidor de desenvolvimento do Vite (npm run dev) so em ambiente local.
        if (app()->isLocal() && Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(public_path('hot')));
            $ws = preg_replace('#^http#', 'ws', $hot);
            array_push($scriptSrc, $hot);
            array_push($styleSrc, $hot);
            array_push($connectSrc, $hot, $ws);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            'style-src '.implode(' ', $styleSrc),
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connectSrc),
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}

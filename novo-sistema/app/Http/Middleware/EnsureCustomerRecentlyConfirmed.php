<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acoes sensiveis da conta do cliente (exportar os dados, excluir a conta,
 * trocar o e-mail) pedem a senha de novo se a ultima confirmacao tem mais de
 * alguns minutos (config barbearia.security.customer_reauth_minutes). Uma
 * sessao esquecida aberta num aparelho emprestado nao basta para levar os
 * dados ou apagar a conta.
 *
 * Cliente sem senha (so link magico) cria uma primeiro: a tela de
 * confirmacao explica e leva para "Senha".
 */
class EnsureCustomerRecentlyConfirmed
{
    public const SESSION_KEY = 'account.confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isRecent($request)) {
            return $next($request);
        }

        // Volta para onde estava: a propria pagina (GET) ou a anterior (envio de
        // formulario). A anterior pode vir do cabecalho Referer: so endereco do
        // proprio site (nunca um redirecionamento para fora).
        $volta = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();
        if (! str_starts_with($volta, url('/').'/')) {
            $volta = route('account.privacy');
        }
        $request->session()->put('url.intended', $volta);

        return redirect()->route('account.confirm.show');
    }

    public static function isRecent(Request $request): bool
    {
        $em = $request->session()->get(self::SESSION_KEY);
        $janela = (int) config('barbearia.security.customer_reauth_minutes', 15) * 60;

        return is_int($em) && $em <= time() && time() - $em < $janela;
    }

    public static function markConfirmed(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, time());
    }
}

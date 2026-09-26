<?php

namespace Tests\Feature\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * DENY BY DEFAULT aplicado as rotas: toda rota precisa estar na lista publica
 * explicita OU ter autenticacao + permissao ('can:'). Rota nova que esqueca
 * disso quebra este teste (e o CI).
 */
class RouteAuthorizationTest extends TestCase
{
    /** Rotas publicas conhecidas e o motivo de serem publicas. */
    private const PUBLICAS = [
        'home' => 'pagina inicial',
        'login' => 'formulario de login',
        'login.attempt' => 'envio do login (limitado por throttle)',
    ];

    /** Rotas que so exigem estar logado (sem permissao especifica). */
    private const SO_AUTENTICADAS = [
        'logout' => 'qualquer usuario logado pode sair',
    ];

    public function test_toda_rota_tem_protecao_declarada(): void
    {
        $problemas = [];

        /** @var Route $rota */
        foreach (RouteFacade::getRoutes() as $rota) {
            $nome = $rota->getName();
            $uri = $rota->uri();
            $middlewares = $rota->gatherMiddleware();

            if ($uri === 'up' || isset(self::PUBLICAS[$nome])) {
                continue;
            }

            // Referencias visuais: so existem com a flag ligada (404 em producao).
            if (in_array('prototypes', $middlewares, true)) {
                continue;
            }

            $temAuth = in_array('auth', $middlewares, true);
            $temCan = (bool) array_filter($middlewares, fn ($m) => is_string($m) && str_starts_with($m, 'can:'));

            if (isset(self::SO_AUTENTICADAS[$nome])) {
                $temAuth || $problemas[] = "{$uri}: deveria exigir login";

                continue;
            }

            if (! $temAuth || ! $temCan) {
                $problemas[] = "{$uri} ({$nome}): falta ".(! $temAuth ? 'auth ' : '').(! $temCan ? 'can:' : '');
            }
        }

        $this->assertSame([], $problemas, "Rotas sem protecao:\n".implode("\n", $problemas));
    }

    public function test_rotas_do_painel_revalidam_usuario_ativo(): void
    {
        foreach (RouteFacade::getRoutes() as $rota) {
            if (str_starts_with((string) $rota->getName(), 'panel.')) {
                $this->assertContains('staff.active', $rota->gatherMiddleware(), $rota->uri());
            }
        }
    }

    public function test_nenhuma_rota_publica_altera_dados_por_get(): void
    {
        foreach (RouteFacade::getRoutes() as $rota) {
            $nome = (string) $rota->getName();
            if (in_array('GET', $rota->methods(), true)) {
                $this->assertDoesNotMatchRegularExpression('/(delete|destroy|excluir|remove|logout|sair)/i', $nome.' '.$rota->uri(), "Acao destrutiva por GET: {$rota->uri()}");
            }
        }
    }
}

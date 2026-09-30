<?php

namespace Tests\Feature\Security;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * DENY BY DEFAULT aplicado as rotas. Toda rota precisa estar na lista
 * publica explicita OU ter:
 *   1. autenticacao com guard EXPLICITO (auth:web ou auth:customer; "auth"
 *      sozinho usaria o guard padrao e poderia aceitar a conta errada);
 *   2. revalidacao da conta (staff.active / customer.active);
 *   3. permissao ('can:habilidade' declarada ou 'can:metodo,registro' de
 *      uma Policy registrada).
 * Rota nova que esqueca qualquer item quebra este teste (e o CI).
 */
class RouteAuthorizationTest extends TestCase
{
    /** Rotas publicas conhecidas e o motivo de serem publicas. */
    private const PUBLICAS = [
        'home' => 'pagina inicial',
        // Equipe: acesso (guest:web)
        'staff.login' => 'formulario de login da equipe',
        'staff.login.attempt' => 'envio do login (throttle:login)',
        'staff.password.request' => 'formulario "esqueci a senha"',
        'staff.password.email' => 'pedido do link (throttle:email-requests, resposta neutra)',
        'staff.password.reset' => 'formulario com o token do e-mail',
        'staff.password.update' => 'redefinicao (token validado pelo broker, throttle:token-use)',
        // Clientes: acesso (guest:customer)
        'customer.login' => 'formulario de login do cliente',
        'customer.login.attempt' => 'envio do login (throttle:login)',
        'customer.register' => 'formulario de cadastro',
        'customer.register.store' => 'cadastro (throttle:registration, resposta neutra)',
        'customer.magic.request' => 'formulario do link magico',
        'customer.magic.send' => 'pedido do link (throttle:email-requests, resposta neutra)',
        'customer.magic.show' => 'tela do link (so mostra o botao)',
        'customer.magic.consume' => 'login pelo token de uso unico (throttle:token-use)',
        'customer.password.request' => 'formulario "esqueci a senha"',
        'customer.password.email' => 'pedido do link (throttle:email-requests, resposta neutra)',
        'customer.password.reset' => 'formulario com o token do e-mail',
        'customer.password.update' => 'redefinicao (token validado pelo broker, throttle:token-use)',
        'customer.verification.verify' => 'link assinado de confirmacao de e-mail (signed)',
    ];

    /** Rotas que so exigem estar logado (sem permissao especifica). */
    private const SO_AUTENTICADAS = [
        'staff.logout' => 'auth:web',
    ];

    /**
     * @return iterable<Route>
     */
    private function rotas(): iterable
    {
        return RouteFacade::getRoutes();
    }

    private function temCan(Route $rota): bool
    {
        return (bool) array_filter($rota->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
    }

    public function test_toda_rota_tem_protecao_declarada(): void
    {
        $problemas = [];

        foreach ($this->rotas() as $rota) {
            $nome = (string) $rota->getName();
            $uri = $rota->uri();
            $m = $rota->gatherMiddleware();

            if ($uri === 'up' || isset(self::PUBLICAS[$nome]) || in_array('prototypes', $m, true)) {
                continue;
            }

            if (isset(self::SO_AUTENTICADAS[$nome])) {
                in_array(self::SO_AUTENTICADAS[$nome], $m, true) || $problemas[] = "{$uri}: deveria exigir ".self::SO_AUTENTICADAS[$nome];

                continue;
            }

            $guard = in_array('auth:web', $m, true) ? 'web' : (in_array('auth:customer', $m, true) ? 'customer' : null);
            $revalida = $guard === 'web' ? 'staff.active' : 'customer.active';

            if ($guard === null) {
                $problemas[] = "{$uri} ({$nome}): falta auth:web ou auth:customer";
            } elseif (! in_array($revalida, $m, true)) {
                $problemas[] = "{$uri} ({$nome}): falta {$revalida}";
            }
            if (! $this->temCan($rota)) {
                $problemas[] = "{$uri} ({$nome}): falta can:";
            }
            if (! in_array('auth.session', $m, true)) {
                $problemas[] = "{$uri} ({$nome}): falta auth.session (derrubar sessao quando a senha muda)";
            }
        }

        $this->assertSame([], $problemas, "Rotas sem protecao:\n".implode("\n", $problemas));
    }

    public function test_nenhuma_rota_usa_auth_sem_guard(): void
    {
        foreach ($this->rotas() as $rota) {
            $this->assertNotContains('auth', $rota->gatherMiddleware(), "{$rota->uri()}: use auth:web ou auth:customer");
        }
    }

    public function test_toda_permissao_usada_nas_rotas_existe(): void
    {
        foreach ($this->rotas() as $rota) {
            foreach ($rota->gatherMiddleware() as $m) {
                if (! is_string($m) || ! str_starts_with($m, 'can:')) {
                    continue;
                }

                $partes = explode(',', substr($m, 4));
                if (count($partes) === 1) {
                    $this->assertTrue(Gate::has($partes[0]), "{$rota->uri()}: habilidade '{$partes[0]}' nao declarada em config/permissions.php");
                } else {
                    // can:metodo,parametro -> o parametro precisa estar na URI e o
                    // model dele precisa ter Policy registrada (verificado abaixo).
                    $this->assertContains($partes[1], $rota->parameterNames(), "{$rota->uri()}: {$m} sem o parametro na rota");
                }
            }
        }
    }

    public function test_models_das_rotas_com_policy_tem_policy_registrada(): void
    {
        $models = [
            User::class,
            Customer::class,
            Professional::class,
            Appointment::class,
            Service::class,
            ServiceCategory::class,
        ];

        foreach ($models as $model) {
            $this->assertNotNull(Gate::getPolicyFor($model), "{$model} sem Policy");
        }
    }

    public function test_areas_usam_o_guard_da_propria_conta(): void
    {
        foreach ($this->rotas() as $rota) {
            $nome = (string) $rota->getName();
            $m = $rota->gatherMiddleware();

            if (str_starts_with($nome, 'panel.')) {
                $this->assertContains('auth:web', $m, $rota->uri());
                $this->assertContains('staff.active', $m, $rota->uri());
                $this->assertContains('staff.password', $m, $rota->uri());
            }
            if (str_starts_with($nome, 'account.')) {
                $this->assertContains('auth:customer', $m, $rota->uri());
                $this->assertContains('customer.active', $m, $rota->uri());
            }
        }
    }

    public function test_paginas_de_conta_e_de_acesso_nao_ficam_em_cache(): void
    {
        foreach ($this->rotas() as $rota) {
            $nome = (string) $rota->getName();
            if (preg_match('/^(panel|account|staff|customer)\./', $nome)) {
                $this->assertContains('no-store', $rota->gatherMiddleware(), $rota->uri());
            }
        }
    }

    public function test_formularios_que_disparam_e_mail_ou_testam_segredo_tem_limite(): void
    {
        $limites = [
            'staff.login.attempt' => 'throttle:login',
            'customer.login.attempt' => 'throttle:login',
            'staff.password.email' => 'throttle:email-requests',
            'customer.password.email' => 'throttle:email-requests',
            'customer.magic.send' => 'throttle:email-requests',
            'customer.register.store' => 'throttle:registration',
            'staff.password.update' => 'throttle:token-use',
            'customer.password.update' => 'throttle:token-use',
            'customer.magic.consume' => 'throttle:token-use',
            'customer.verification.verify' => 'throttle:token-use',
            'panel.password.update' => 'throttle:password-check',
            'panel.password.confirm.store' => 'throttle:password-check',
            'account.password.update' => 'throttle:password-check',
        ];

        foreach ($limites as $nome => $throttle) {
            $this->assertContains($throttle, RouteFacade::getRoutes()->getByName($nome)?->gatherMiddleware() ?? [], $nome);
        }
    }

    public function test_nenhuma_rota_altera_dados_por_get(): void
    {
        foreach ($this->rotas() as $rota) {
            $nome = (string) $rota->getName();
            if (in_array('GET', $rota->methods(), true)) {
                $this->assertDoesNotMatchRegularExpression('/(delete|destroy|excluir|remove|logout|sair|consume|store|update)/i', $nome.' '.$rota->uri(), "Acao por GET: {$rota->uri()}");
            }
        }
    }
}

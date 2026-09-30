<?php

namespace Tests\Feature\Security;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** Telas publicas de acesso (equipe e clientes). */
    private const TELAS_DE_ACESSO = ['/painel/entrar', '/painel/esqueci-a-senha', '/entrar', '/cadastro', '/entrar/link', '/esqueci-a-senha'];

    public function test_cabecalhos_de_seguranca_e_csp_estrita(): void
    {
        foreach (self::TELAS_DE_ACESSO as $pagina) {
            $resposta = $this->get($pagina)->assertOk();

            $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
            $resposta->assertHeader('X-Frame-Options', 'DENY');
            $resposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

            $csp = $resposta->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("form-action 'self'", $csp);
            $this->assertStringNotContainsString('unsafe-inline', $csp);
            $this->assertStringNotContainsString('unsafe-eval', $csp);
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        }
    }

    public function test_html_nao_usa_estilo_nem_handler_inline(): void
    {
        $dono = User::factory()->owner()->create();
        $cliente = Customer::factory()->create();

        $paginas = array_merge(self::TELAS_DE_ACESSO, ['/prototipos/home', '/prototipos/agendamento', '/prototipos/agenda', '/design-system']);
        foreach ($paginas as $pagina) {
            $this->assertSemInline($this->get($pagina)->assertOk()->getContent(), $pagina);
        }

        $this->withSession(['auth.password_confirmed_at' => time()]);
        foreach (['/painel', '/painel/minha-conta', '/painel/minha-conta/senha', '/painel/usuarios', '/painel/usuarios/novo', '/painel/auditoria'] as $pagina) {
            $this->assertSemInline($this->actingAs($dono, 'web')->get($pagina)->assertOk()->getContent(), $pagina);
        }
        foreach (['/minha-conta', '/minha-conta/dados', '/minha-conta/senha'] as $pagina) {
            $this->assertSemInline($this->actingAs($cliente, 'customer')->get($pagina)->assertOk()->getContent(), $pagina);
        }
    }

    private function assertSemInline(string $html, string $pagina): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html, "style=\"\" em {$pagina} (bloqueado pela CSP)");
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load|input|error)=/i', $html, "handler inline em {$pagina}");
    }

    public function test_links_gerados_usam_app_url_e_ignoram_o_cabecalho_host(): void
    {
        // Regressao do achado S-06 (envenenamento de Host) do sistema antigo:
        // e o que garante que o link de redefinicao nunca aponta para outro site.
        $this->withHeader('Host', 'site-do-atacante.example')->get(route('staff.login'));

        $this->assertStringStartsWith('https://barbearia.test/', URL::route('staff.password.reset', 'tok'));
        $this->assertStringStartsWith('https://barbearia.test/', URL::route('customer.magic.show', 'tok'));
    }

    public function test_formularios_levam_token_csrf(): void
    {
        foreach (self::TELAS_DE_ACESSO as $pagina) {
            $this->assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $this->get($pagina)->getContent(), $pagina);
        }
    }

    public function test_post_sem_token_csrf_e_recusado(): void
    {
        // O Laravel desliga a verificacao em testes automatizados; aqui ela e
        // chamada de verdade, com a mesma configuracao da aplicacao.
        $middleware = new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $request = Request::create('/entrar', 'POST', ['email' => 'a@exemplo.test', 'password' => 'x']);
        $request->setLaravelSession(app('session.store'));

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('passou'));
    }

    public function test_nenhuma_rota_esta_fora_da_protecao_csrf(): void
    {
        $excecoes = (new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            /** @return array<int, string> */
            public function lista(): array
            {
                return $this->getExcludedPaths();
            }
        })->lista();

        $this->assertSame([], $excecoes);
    }

    public function test_cookie_de_sessao_e_http_only_same_site_e_seguro_quando_https(): void
    {
        config(['session.secure' => true]);

        $cookie = collect($this->get('/entrar')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_paginas_de_acesso_e_de_conta_nao_ficam_em_cache(): void
    {
        $this->get('/entrar')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs(Customer::factory()->create(), 'customer')->get('/minha-conta')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_sessao_criptografada_e_com_validade(): void
    {
        $this->assertTrue((bool) config('session.encrypt'));
        $this->assertGreaterThan(0, (int) config('session.lifetime'));
        $this->assertLessThanOrEqual(120, (int) config('session.lifetime'));
    }
}

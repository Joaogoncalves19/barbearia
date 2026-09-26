<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabecalhos_de_seguranca_e_csp_estrita(): void
    {
        $resposta = $this->get(route('login'));

        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $resposta->assertHeader('X-Frame-Options', 'DENY');
        $resposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $resposta->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
    }

    public function test_html_nao_usa_estilo_nem_handler_inline(): void
    {
        foreach (['/entrar', '/prototipos/home', '/prototipos/agendamento', '/prototipos/agenda', '/design-system'] as $pagina) {
            $html = $this->get($pagina)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html, "style=\"\" em {$pagina} (bloqueado pela CSP)");
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load|input|error)=/i', $html, "handler inline em {$pagina}");
        }
    }

    public function test_links_gerados_usam_app_url_e_ignoram_o_cabecalho_host(): void
    {
        // Regressao do achado S-06 (envenenamento de Host) do sistema antigo.
        $this->withHeader('Host', 'site-do-atacante.example')->get(route('login'));

        $this->assertStringStartsWith('https://barbearia.test/', URL::route('login'));
    }

    public function test_formularios_exigem_token_csrf(): void
    {
        $html = $this->get(route('login'))->getContent();
        $this->assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $html);
    }

    public function test_campo_de_senha_nunca_e_repreenchido(): void
    {
        $this->from(route('login'))->post(route('login.attempt'), ['email' => 'x@barbearia.test', 'password' => 'segredo-digitado']);

        $this->get(route('login'))->assertDontSee('segredo-digitado');
    }
}

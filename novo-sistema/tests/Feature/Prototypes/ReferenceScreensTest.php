<?php

namespace Tests\Feature\Prototypes;

use Tests\TestCase;

class ReferenceScreensTest extends TestCase
{
    private const TELAS = [
        '/prototipos', '/prototipos/home', '/prototipos/servicos', '/prototipos/agendamento',
        '/prototipos/painel', '/prototipos/agenda', '/prototipos/agenda?visao=lista', '/design-system',
    ];

    public function test_telas_de_referencia_abrem_e_se_identificam_como_prototipo(): void
    {
        foreach (self::TELAS as $tela) {
            $this->get($tela)
                ->assertOk()
                ->assertSee('Protótipo de referência visual', false)
                ->assertSee('noindex', false);
        }
    }

    public function test_dados_de_exemplo_sempre_marcados(): void
    {
        foreach (['/prototipos/home', '/prototipos/servicos', '/prototipos/painel', '/prototipos/agenda'] as $tela) {
            $this->get($tela)->assertSee('exemplo', false);
        }
    }

    public function test_home_nao_exibe_numeros_ficticios_nem_link_de_admin(): void
    {
        $html = $this->get('/prototipos/home')->getContent();
        $this->assertStringNotContainsString('1500', $html);
        $this->assertStringNotContainsString('Clientes Satisfeitos', $html);
        $this->assertStringNotContainsString('admin', strtolower(strip_tags($html)));
    }

    public function test_direcao_visual_vem_do_parametro_e_e_validada(): void
    {
        $this->get('/prototipos/home?direcao=b')->assertSee('data-direcao="b"', false);
        $this->get('/prototipos/home?direcao=<script>')->assertSee('data-direcao="a"', false);
    }

    public function test_direcao_a_e_a_oficial_e_b_nao_vaza_para_o_produto(): void
    {
        // Telas do produto: sempre A, sem a folha de estilo historica da B.
        $html = $this->get('/entrar')->assertOk()->getContent();
        $this->assertStringContainsString('data-direcao="a"', $html);
        $this->assertStringNotContainsString('direcao-b', $html);

        // Mesmo pedindo B explicitamente, sem a flag de prototipos o layout fica em A.
        config(['barbearia.prototypes_enabled' => false]);
        $render = $this->blade('<x-layouts.document direction="b">x</x-layouts.document>');
        $render->assertSee('data-direcao="a"', false);
        $render->assertDontSee('direcao-b', false);
    }

    public function test_desligadas_em_producao_respondem_404(): void
    {
        config(['barbearia.prototypes_enabled' => false]);

        foreach (self::TELAS as $tela) {
            $this->get($tela)->assertNotFound();
        }
    }
}

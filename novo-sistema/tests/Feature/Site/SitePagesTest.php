<?php

namespace Tests\Feature\Site;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\SiteContent\Support\SiteSettings;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\System\Models\Setting;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * Site publico (Fase 11): mostra so o que esta publicado, le o MESMO
 * dominio do painel e da agenda (sem regra paralela), nao inventa nada e
 * nao expoe dado de cliente nem da equipe.
 */
class SitePagesTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
        $this->joao->forceFill(['is_public' => true, 'headline' => 'Degradê e navalha'])->save();
    }

    public function test_inicio_mostra_servicos_e_equipe_publicados_e_nada_inventado(): void
    {
        $oculto = Service::factory()->create(['name' => 'Servico Interno', 'is_public' => false]);
        $inativo = Service::factory()->create(['name' => 'Servico Antigo', 'is_active' => false]);
        $foraDoSite = Professional::factory()->create(['display_name' => 'Pedro Oculto', 'is_public' => false]);
        $semAgenda = Professional::factory()->create(['display_name' => 'Paulo Sem Agenda', 'is_public' => true, 'is_bookable' => false]);
        $inativoPro = Professional::factory()->create(['display_name' => 'Ana Inativa', 'is_public' => true, 'is_active' => false]);

        $r = $this->get('/')->assertOk();

        $r->assertSee('Corte')->assertSee('R$ 50,00')->assertSee('30 min')->assertSee('João')->assertSee('Degradê e navalha')
            ->assertSee('Agendar horário')->assertSee(route('booking.services'), false);
        foreach ([$oculto->name, $inativo->name, $foraDoSite->display_name, $semAgenda->display_name, $inativoPro->display_name] as $nao) {
            $r->assertDontSee($nao);
        }
        // Sem conteudo configurado: nada de endereco, nota, depoimento, galeria ou numero inventado.
        $r->assertDontSee('Rua Exemplo')->assertDontSee('avaliações de clientes')->assertDontSee('O que dizem')->assertDontSee('id="galeria"', false)
            ->assertDontSee('Clientes Satisfeitos')->assertDontSee('Anos de Experiência');
        // Horario vem da agenda.
        $r->assertSee('Horário de funcionamento')->assertSee('9h às 20h');
    }

    public function test_preco_alterado_no_painel_aparece_no_site_sem_regra_paralela(): void
    {
        $this->get(route('site.services'))->assertSee('R$ 50,00');

        $this->corte->forceFill(['price_cents' => 6500, 'name' => 'Corte Clássico'])->save();
        $this->corte->forceFill(['is_featured' => true])->save();

        $this->get(route('site.services'))->assertSee('Corte Clássico')->assertSee('R$ 65,00')->assertDontSee('R$ 50,00');
        $this->get('/')->assertSee('R$ 65,00');
        $this->get(route('booking.services'))->assertSee('R$ 65,00');

        $this->corte->forceFill(['is_public' => false])->save();
        $this->get(route('site.services'))->assertDontSee('Corte Clássico');
        $this->get(route('booking.professional', $this->corte))->assertNotFound();
    }

    public function test_pagina_do_profissional_leva_aos_horarios_dele_e_so_existe_se_publicado(): void
    {
        $r = $this->get(route('site.professional', $this->joao))->assertOk()->assertSee('Agendar com João');
        $link = route('booking.slots', ['service' => $this->corte, 'profissional' => $this->joao->slug]);
        $r->assertSee($link, false);
        $this->get($link)->assertOk();

        $this->joao->forceFill(['is_public' => false])->save();
        $this->get(route('site.professional', $this->joao))->assertNotFound();
        $this->get($link)->assertNotFound();
        $this->get(route('site.professional', ['professional' => 'nao-existe']))->assertNotFound();
        $this->get('/equipe/..%2F..%2Fetc')->assertNotFound();
    }

    public function test_pelo_site_so_agenda_o_que_e_publico_a_equipe_agenda_tudo(): void
    {
        $this->corte->forceFill(['is_public' => false])->save();

        try {
            $this->book($this->terca, '10:00');
            $this->fail('canal do cliente não agenda serviço fora do site');
        } catch (SlotUnavailable $e) {
            $this->assertContains('service_not_public', $e->result->reasons);
        }
        $a = $this->book($this->terca, '10:00', channel: Channel::Staff);
        $this->assertSame(AppointmentSource::Staff, $a->source);

        $this->corte->forceFill(['is_public' => true])->save();
        $this->joao->forceFill(['is_public' => false])->save();
        $this->assertSame([], $this->freeTimes($this->terca), 'sem profissional público, sem horário pelo site');
        $this->assertNotSame([], $this->freeTimes($this->terca, channel: Channel::Staff));
        $this->expectException(SlotUnavailable::class);
        $this->booking()->book(new BookingRequest(service: $this->corte, professional: null, start: $this->at($this->terca, '11:00'),
            channel: Channel::Customer, source: AppointmentSource::Online, customer: $this->cliente, actor: $this->cliente));
    }

    public function test_avaliacoes_so_reais_aprovadas_e_destacadas_sem_expor_o_cliente(): void
    {
        $maria = Customer::factory()->create(['name' => 'Maria Fictícia Souza', 'email' => 'maria.ficticia@exemplo.test']);
        $mk = fn (int $nota, ReviewStatus $st, bool $destaque, string $txt) => Review::query()->create([
            'customer_id' => $maria->id, 'professional_id' => $this->joao->id, 'rating' => $nota, 'comment' => $txt, 'status' => $st, 'is_featured' => $destaque, 'reviewed_at' => now(), 'is_legacy' => true,
        ]);
        $mk(5, ReviewStatus::Approved, true, 'Ótimo <script>alert(1)</script>');
        $mk(4, ReviewStatus::Approved, false, 'Bom');
        $mk(1, ReviewStatus::Pending, false, 'Comentário pendente');
        $mk(1, ReviewStatus::Rejected, false, 'Comentário recusado');

        $r = $this->get('/');
        $r->assertDontSee('avaliações de clientes')->assertSee('Maria S.')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Comentário pendente')->assertDontSee('Comentário recusado')->assertDontSee('maria.ficticia@exemplo.test')->assertDontSee('Fictícia Souza');

        $mk(3, ReviewStatus::Approved, false, 'Ok');
        $this->get('/')->assertSee('Nota 4,0 de 5')->assertSee('3 avaliações de clientes');
    }

    public function test_nenhuma_pagina_publica_expoe_dados_de_clientes_ou_da_equipe(): void
    {
        $cliente = Customer::factory()->create(['email' => 'cliente.secreto@exemplo.test']);
        $usuario = User::factory()->owner()->create(['username' => 'dono-secreto', 'email' => 'dono.secreto@exemplo.test']);
        $this->joao->forceFill(['user_id' => $usuario->id])->save();
        Plan::factory()->withVersion(9900, [$this->corte->id])->create(['name' => 'Clube Público']);

        foreach (['/', route('site.services'), route('site.team'), route('site.professional', $this->joao), route('site.plans'), route('sitemap')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ([(string) $cliente->email, (string) $cliente->cpf, 'dono-secreto', 'dono.secreto@exemplo.test', (string) $cliente->name] as $segredo) {
                $this->assertStringNotContainsString($segredo, (string) $html, "{$url} expõe {$segredo}");
            }
        }
        $this->get(route('site.plans'))->assertSee('Clube Público')->assertSee('R$ 99,00');
    }

    public function test_paginas_que_dependem_de_conteudo_respondem_404_sem_ele(): void
    {
        $this->get(route('site.plans'))->assertNotFound();
        $this->get(route('site.privacy'))->assertNotFound();
        $this->get(route('site.terms'))->assertNotFound();
        $this->get('/')->assertDontSee(route('site.privacy'), false);

        SiteSettings::save(['privacy_policy' => "## Dados que coletamos\nNome e <b>e-mail</b>.\n\nSegundo parágrafo."], null);
        $this->get(route('site.privacy'))->assertOk()->assertSee('<h2 class="h3">Dados que coletamos</h2>', false)->assertSee('&lt;b&gt;e-mail&lt;/b&gt;', false);
        $this->get('/')->assertSee(route('site.privacy'), false);
    }

    public function test_seo_titulo_descricao_canonico_open_graph_e_dados_estruturados_sem_inventar(): void
    {
        SiteSettings::save(['name' => 'Barbearia Fictícia do Teste', 'hero_subtitle' => 'Corte e barba no centro.', 'phone' => '(11) 3000-0000', 'instagram' => 'https://www.instagram.com/ficticia'], null);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Barbearia Fictícia do Teste</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Corte e barba no centro.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('home').'">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Barbearia Fictícia do Teste">', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'um h1 por página');
        preg_match('#<script type="application/ld\+json" nonce="[^"]+">(.+?)</script>#s', $html, $m);
        $ld = json_decode($m[1] ?? '', true);
        $this->assertSame(['BarberShop', 'Barbearia Fictícia do Teste', '(11) 3000-0000', ['https://www.instagram.com/ficticia']], [$ld['@type'], $ld['name'], $ld['telephone'], $ld['sameAs']]);
        $this->assertArrayNotHasKey('address', $ld, 'sem endereço configurado, sem endereço nos dados');
        $this->assertArrayNotHasKey('aggregateRating', $ld);
        $this->assertArrayNotHasKey('priceRange', $ld);
        $this->assertCount(1, $ld['openingHoursSpecification']);
        $this->assertSame(['09:00', '20:00'], [$ld['openingHoursSpecification'][0]['opens'], $ld['openingHoursSpecification'][0]['closes']]);

        $this->get(route('site.services'))->assertSee('<title>Serviços e preços · Barbearia Fictícia do Teste</title>', false);
    }

    public function test_sitemap_e_robots(): void
    {
        $oculto = Professional::factory()->create(['display_name' => 'Fora do Site', 'is_public' => false]);
        $xml = (string) $this->get(route('sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.route('site.professional', $this->joao).'</loc>', $xml);
        $this->assertStringNotContainsString((string) $oculto->slug, $xml);
        $this->assertStringNotContainsString('/painel', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->app['env'] = 'production';
        $robots = (string) $this->get('/robots.txt')->getContent();
        $this->assertStringContainsString('Disallow: /painel', $robots);
        $this->assertStringContainsString('Disallow: /minha-conta', $robots);
        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $robots);
    }

    public function test_conteudo_do_sistema_antigo_sem_os_textos_padrao(): void
    {
        Setting::query()->create(['key' => 'legacy.config_geral', 'value' => ['nome_barbearia' => 'Barbearia Real Importada', 'telefone_contato' => '(00) 00000-0000', 'endereco' => 'Rua Exemplo, 123 - Centro, Sua Cidade']]);
        Setting::query()->create(['key' => 'legacy.landing_page', 'value' => ['hero_subtitle' => 'Agende seu horário com os melhores profissionais da região e viva uma experiência premium.', 'stat1_number' => '1500']]);

        $this->get('/')->assertSee('Barbearia Real Importada')->assertDontSee('(00) 00000-0000')->assertDontSee('Rua Exemplo')->assertDontSee('experiência premium')->assertDontSee('1500');
    }

    public function test_aberto_agora_vem_da_agenda(): void
    {
        $this->travelTo($this->at($this->segunda, '10:30'));
        $this->get('/')->assertSee('Aberto agora até 20h');
        $this->travelTo($this->at($this->segunda, '21:00'));
        $this->get('/')->assertSee('Abre amanhã às 9h');
    }
}

<?php

namespace Tests\Feature\Site;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\SiteContent\Support\Appearance;
use App\Modules\SiteContent\Support\Theme;
use App\Modules\System\Models\AuditLog;
use App\Modules\System\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * Temas visuais (temas-visuais.md): o tema e uma configuracao da barbearia,
 * escolhida so pelo proprietario, que vale para o site, a conta do cliente e
 * o painel; trocar de tema nao mexe em dado nem em regra. A parte visual de
 * cada tema (contraste, telas sem rolagem lateral, axe) e testada no
 * navegador (tests/e2e/temas.spec.js).
 */
class AppearanceThemesTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
        $this->dono = User::factory()->owner()->create();
    }

    private function escolher(string $tema, ?User $quem = null): TestResponse
    {
        return $this->actingAs($quem ?? $this->dono, 'web')->put(route('panel.appearance.update'), ['theme' => $tema]);
    }

    private function temaDaPagina(string $html): ?string
    {
        return preg_match('/<html[^>]*data-tema="([a-z-]+)"/', $html, $m) ? $m[1] : null;
    }

    public function test_tema_padrao_e_o_oficio_no_site_no_acesso_no_painel_e_na_conta(): void
    {
        $this->assertSame('oficio', Appearance::current()->theme()->key);
        $this->assertSame('oficio', $this->temaDaPagina($this->get(route('home'))->assertOk()->getContent()));
        $this->assertSame('oficio', $this->temaDaPagina($this->get(route('customer.login'))->assertOk()->getContent()));
        $this->assertSame('oficio', $this->temaDaPagina($this->actingAs($this->dono, 'web')->get(route('panel.home'))->assertOk()->getContent()));
        $this->assertSame('oficio', $this->temaDaPagina($this->actingAs($this->cliente, 'customer')->get(route('account.home'))->assertOk()->getContent()));
    }

    public function test_os_oito_temas_podem_ser_escolhidos_e_valem_em_todas_as_areas(): void
    {
        $this->assertCount(8, Theme::all());
        foreach (Theme::all() as $tema) {
            $this->escolher($tema->key)->assertRedirect(route('panel.appearance'))->assertSessionHas('status');
            $this->assertSame($tema->key, Appearance::current()->theme()->key);

            $this->assertSame($tema->key, $this->temaDaPagina($this->get(route('home'))->getContent()), "site no tema {$tema->key}");
            $this->assertSame($tema->key, $this->temaDaPagina($this->get(route('site.services'))->getContent()));
            $this->assertSame($tema->key, $this->temaDaPagina($this->actingAs($this->dono, 'web')->get(route('panel.agenda'))->getContent()), "painel no tema {$tema->key}");
            $this->assertSame($tema->key, $this->temaDaPagina($this->actingAs($this->cliente, 'customer')->get(route('account.home'))->getContent()), "conta no tema {$tema->key}");
            $this->assertSame($tema->key, $this->temaDaPagina($this->get('/pagina-que-nao-existe')->assertNotFound()->getContent()), "erro no tema {$tema->key}");
        }
    }

    public function test_superficies_seguem_o_tema(): void
    {
        $this->escolher('minimal');
        $site = $this->get(route('home'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-superficie="clara"/', $site, 'Minimal: site claro');
        $painel = $this->actingAs($this->dono, 'web')->get(route('panel.home'))->getContent();
        $this->assertStringContainsString('class="sidebar" data-superficie="clara"', $painel, 'Minimal: barra lateral clara');

        $this->escolher('black-label');
        $painel = $this->actingAs($this->dono, 'web')->get(route('panel.home'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-superficie="escura"/', $painel, 'Black Label: painel escuro');

        $this->escolher('oficio');
        $this->assertMatchesRegularExpression('/<html[^>]*data-superficie="escura"/', $this->get(route('home'))->getContent());
        $this->assertMatchesRegularExpression('/<html[^>]*data-superficie="clara"/', $this->actingAs($this->dono, 'web')->get(route('panel.home'))->getContent());
    }

    public function test_tema_escolhido_continua_depois_de_sair_e_entrar_de_novo(): void
    {
        $this->dono->forceFill(['username' => 'dono-temas'])->save();
        $this->escolher('gentlemans-club');
        $this->post(route('staff.logout'))->assertRedirect();
        $this->assertGuest('web');

        $this->assertSame('gentlemans-club', $this->temaDaPagina($this->get(route('staff.login'))->getContent()));
        $this->post(route('staff.login.attempt'), ['identifier' => 'dono-temas', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($this->dono, 'web');
        $this->assertSame('gentlemans-club', $this->temaDaPagina($this->get(route('panel.home'))->getContent()), 'continua depois de entrar de novo');
        $outro = User::factory()->role(StaffRole::Reception)->create();
        $this->assertSame('gentlemans-club', $this->temaDaPagina($this->actingAs($outro, 'web')->get(route('panel.home'))->getContent()), 'vale para toda a equipe');
    }

    public function test_so_o_proprietario_ve_e_troca_o_tema(): void
    {
        // Visitante e cliente nao chegam ao painel.
        $this->put(route('panel.appearance.update'), ['theme' => 'red-barber'])->assertRedirect(route('staff.login'));
        $this->actingAs($this->cliente, 'customer')->put(route('panel.appearance.update'), ['theme' => 'red-barber'])->assertRedirect(route('staff.login'));
        $this->assertSame('oficio', Appearance::current()->theme()->key);

        foreach ([StaffRole::Manager, StaffRole::Reception, StaffRole::Finance, StaffRole::Professional] as $papel) {
            $u = User::factory()->role($papel)->create();
            $this->actingAs($u, 'web')->get(route('panel.appearance'))->assertForbidden();
            $this->actingAs($u, 'web')->get(route('panel.appearance.preview', 'red-barber'))->assertForbidden();
            $this->escolher('red-barber', $u)->assertForbidden();
        }
        $this->assertSame('oficio', Appearance::current()->theme()->key);

        // Menu: so o proprietario ve "Aparencia".
        $this->actingAs($this->dono, 'web')->get(route('panel.home'))->assertSee(route('panel.appearance'), false);
        $this->actingAs(User::factory()->manager()->create(), 'web')->get(route('panel.home'))->assertDontSee(route('panel.appearance'), false);
    }

    public function test_tema_desconhecido_e_recusado_e_valor_estranho_no_banco_volta_ao_padrao(): void
    {
        $this->escolher('tema-inventado')->assertSessionHasErrors('theme');
        $this->escolher('')->assertSessionHasErrors('theme');
        $this->assertSame('oficio', Appearance::current()->theme()->key);

        Setting::query()->updateOrCreate(['key' => Appearance::KEY], ['value' => ['theme' => 'removido-do-catalogo']]);
        $this->assertSame('oficio', Appearance::current()->theme()->key);
        $this->get(route('home'))->assertOk();
    }

    public function test_troca_e_auditada_com_o_tema_anterior_e_o_novo(): void
    {
        $this->escolher('urban-barber');
        $log = AuditLog::query()->where('action', 'appearance.theme_changed')->sole();
        $this->assertSame($this->dono->id, (int) $log->actor_id);
        $this->assertSame(['tema_anterior' => 'oficio', 'tema_novo' => 'urban-barber'], $log->new_values);

        // Escolher o tema que ja esta em uso nao gera registro novo.
        $this->escolher('urban-barber');
        $this->assertSame(1, AuditLog::query()->where('action', 'appearance.theme_changed')->count());
    }

    public function test_previa_mostra_o_tema_sem_gravar_nada(): void
    {
        $antes = Setting::query()->count();
        $html = $this->actingAs($this->dono, 'web')->get(route('panel.appearance.preview', 'old-school'))->assertOk()->getContent();
        $this->assertSame('old-school', $this->temaDaPagina($html));
        $this->assertStringContainsString('Usar este tema', $html);

        $this->assertSame('oficio', Appearance::current()->theme()->key);
        $this->assertSame($antes, Setting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'appearance.theme_changed')->count());
        $this->assertSame('oficio', $this->temaDaPagina($this->get(route('home'))->getContent()), 'a previa nao vaza para outras paginas');

        $this->actingAs($this->dono, 'web')->get(route('panel.appearance.preview', 'nao-existe'))->assertNotFound();
    }

    public function test_tela_aparencia_mostra_os_oito_temas_com_nome_paleta_e_miniatura(): void
    {
        $html = $this->actingAs($this->dono, 'web')->get(route('panel.appearance'))->assertOk()->getContent();
        foreach (Theme::all() as $tema) {
            $this->assertStringContainsString('data-theme-card="'.$tema->key.'"', $html);
            $this->assertStringContainsString('class="theme-mini" data-tema="'.$tema->key.'"', $html);
            $this->assertStringContainsString(e($tema->name()), $html);
            $this->assertStringContainsString(e($tema->palette()), $html);
        }
        // O tema em uso aparece marcado e sem o botao de usar.
        $this->assertSame(7, substr_count($html, '>Usar este tema<'));
        $this->assertStringContainsString('Em uso', $html);
    }

    public function test_trocar_o_tema_nao_altera_dados_nem_regras(): void
    {
        $this->book($this->terca, '10:00');
        $tabelas = collect(Schema::getTableListing())
            ->map(fn (string $t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->reject(fn (string $t) => in_array($t, ['settings', 'audit_logs', 'sessions', 'cache', 'cache_locks', 'jobs'], true))
            ->values();
        $retrato = fn () => $tabelas->mapWithKeys(fn (string $t) => [$t => md5((string) json_encode(DB::table($t)->get()))])->all();
        $outrasConfiguracoes = fn () => Setting::query()->where('key', '!=', Appearance::KEY)->orderBy('key')->get(['key', 'value'])->toArray();
        $horarios = fn () => array_map(fn (array $s) => $s['start']->toIso8601String().'/'.$s['end']->toIso8601String(), $this->availability()->slots($this->corte, $this->joao, $this->terca, Channel::Customer));
        $permissoes = fn () => collect(StaffRole::cases())->mapWithKeys(fn (StaffRole $r) => [$r->value => User::factory()->make(['role' => $r])->can('settings.manage')])->all();

        $antes = [$retrato(), $outrasConfiguracoes(), $horarios(), $permissoes()];
        $this->assertNotSame([], $antes[2], 'a agenda de teste tem horarios livres');

        foreach (['black-label', 'minimal', 'red-barber', 'oficio'] as $tema) {
            $this->escolher($tema)->assertRedirect();
            $this->assertSame($antes, [$retrato(), $outrasConfiguracoes(), $horarios(), $permissoes()], "tema {$tema} nao muda dados, configuracoes, horarios livres nem permissoes");
        }
    }

    public function test_cada_tema_tem_arquivo_css_importado_e_amostras_que_existem_no_css(): void
    {
        $indice = (string) file_get_contents(resource_path('css/themes/index.css'));
        $base = (string) file_get_contents(resource_path('css/tokens.css'));
        foreach (Theme::all() as $tema) {
            $arquivo = resource_path("css/themes/{$tema->key}.css");
            $this->assertFileExists($arquivo);
            $this->assertStringContainsString("@import './{$tema->key}.css';", $indice);
            $css = strtolower((string) file_get_contents($arquivo).($tema->key === Theme::DEFAULT ? $base : ''));
            $this->assertStringContainsString("[data-tema='{$tema->key}']", $css);
            foreach ($tema->swatches() as [$rotulo, $cor]) {
                $this->assertStringContainsString(strtolower($cor), $css, "amostra {$rotulo} do tema {$tema->key} tem de ser uma cor do proprio tema");
            }
            foreach (Theme::ROLES as $papel) {
                $this->assertContains($tema->surface($papel), ['clara', 'escura']);
            }
        }
    }
}

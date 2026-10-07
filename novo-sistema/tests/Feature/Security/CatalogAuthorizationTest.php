<?php

namespace Tests\Feature\Security;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Autorizacao do catalogo e da equipe (Fase 4): cada acao com a sua
 * habilidade, sempre no servidor. Formulario adulterado nao passa.
 */
class CatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $cat;

    private Service $servico;

    private Professional $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = ServiceCategory::factory()->create();
        $this->servico = Service::factory()->create(['category_id' => $this->cat->id, 'price_cents' => 4500]);
        $this->pro = Professional::factory()->create();
    }

    /**
     * Todas as rotas de escrita do catalogo e da equipe.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function escritas(): array
    {
        return [
            ['post', route('panel.categories.store'), ['name' => 'Nova']],
            ['put', route('panel.categories.update', $this->cat), ['name' => 'X', 'version' => 0]],
            ['post', route('panel.categories.status', $this->cat), ['active' => 0]],
            ['post', route('panel.categories.move', $this->cat), ['direction' => 'up']],
            ['post', route('panel.services.store'), ['name' => 'Novo', 'category_id' => $this->cat->id, 'duration_minutes' => 30, 'price' => '1,00']],
            ['put', route('panel.services.update', $this->servico), ['name' => 'X', 'category_id' => $this->cat->id, 'duration_minutes' => 30, 'version' => 0]],
            ['post', route('panel.services.status', $this->servico), ['active' => 0]],
            ['post', route('panel.services.move', $this->servico), ['direction' => 'up']],
            ['delete', route('panel.services.destroy', $this->servico), []],
            ['post', route('panel.professionals.store'), ['display_name' => 'Novo']],
            ['put', route('panel.professionals.update', $this->pro), ['display_name' => 'X', 'version' => 0]],
            ['post', route('panel.professionals.status', $this->pro), ['active' => 0]],
            ['post', route('panel.professionals.move', $this->pro), ['direction' => 'up']],
            ['put', route('panel.professionals.services.update', $this->pro), ['services' => [$this->servico->id]]],
        ];
    }

    private function assertNadaMudou(): void
    {
        $this->assertSame(1, ServiceCategory::query()->count());
        $this->assertSame(1, Service::query()->count());
        $this->assertSame(1, Professional::query()->count());
        $this->assertTrue($this->servico->fresh()->is_active);
        $this->assertSame(4500, $this->servico->fresh()->price_cents);
        $this->assertTrue($this->pro->fresh()->is_active);
        $this->assertSame(0, $this->pro->services()->count());
    }

    public function test_recepcao_so_consulta_catalogo_e_equipe(): void
    {
        $recepcao = User::factory()->create();

        $this->actingAs($recepcao, 'web')->get(route('panel.services.index'))->assertOk()->assertDontSee('Novo serviço');
        $this->actingAs($recepcao, 'web')->get(route('panel.categories.index'))->assertOk();
        $this->actingAs($recepcao, 'web')->get(route('panel.professionals.index'))->assertOk()->assertDontSee('Novo profissional');
        $this->actingAs($recepcao, 'web')->get(route('panel.services.edit', $this->servico))->assertForbidden();

        foreach ($this->escritas() as [$metodo, $url, $dados]) {
            $this->actingAs($recepcao, 'web')->{$metodo}($url, $dados)->assertForbidden();
        }
        $this->assertNadaMudou();
    }

    public function test_profissional_financeiro_nao_administram_catalogo_nem_equipe(): void
    {
        foreach ([StaffRole::Professional, StaffRole::Finance] as $papel) {
            $u = User::factory()->role($papel)->create();

            $this->actingAs($u, 'web')->get(route('panel.services.index'))->assertForbidden();
            $this->actingAs($u, 'web')->get(route('panel.professionals.index'))->assertForbidden();
            foreach ($this->escritas() as [$metodo, $url, $dados]) {
                $this->actingAs($u, 'web')->{$metodo}($url, $dados)->assertForbidden();
            }
        }
        $this->assertNadaMudou();
    }

    public function test_profissional_nao_edita_a_propria_ficha_nem_os_proprios_servicos(): void
    {
        $u = User::factory()->role(StaffRole::Professional)->create();
        $this->pro->update(['user_id' => $u->id]);

        // Fase 12.5: a propria ficha abre no Perfil da area do profissional (so consulta).
        $this->actingAs($u, 'web')->get(route('panel.professionals.show', $this->pro))->assertRedirect(route('pro.profile'));
        $this->actingAs($u, 'web')->get(route('pro.profile'))->assertOk()->assertDontSee(route('panel.professionals.edit', $this->pro));
        $this->actingAs($u, 'web')->get(route('panel.professionals.edit', $this->pro))->assertForbidden();
        $this->actingAs($u, 'web')->put(route('panel.professionals.services.update', $this->pro), ['services' => [$this->servico->id]])->assertForbidden();
        $this->assertSame(0, $this->pro->services()->count());
    }

    public function test_cliente_nao_acessa_administracao(): void
    {
        $cliente = Customer::factory()->create();

        $this->actingAs($cliente, 'customer')->get(route('panel.services.index'))->assertRedirect(route('staff.login'));
        foreach ($this->escritas() as [$metodo, $url, $dados]) {
            $this->actingAs($cliente, 'customer')->{$metodo}($url, $dados)->assertRedirect(route('staff.login'));
        }
        $this->assertNadaMudou();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        foreach ($this->escritas() as [$metodo, $url, $dados]) {
            $this->{$metodo}($url, $dados)->assertRedirect(route('staff.login'));
        }
        $this->assertNadaMudou();
    }

    public function test_gerente_e_proprietario_administram(): void
    {
        foreach ([StaffRole::Owner, StaffRole::Manager] as $papel) {
            $this->actingAs(User::factory()->role($papel)->create(), 'web')->get(route('panel.services.edit', $this->servico))->assertOk();
        }
    }

    // --- Permissao por campo -----------------------------------------------------------------

    public function test_preco_exige_habilidade_propria_mesmo_com_formulario_adulterado(): void
    {
        config(['permissions.roles.manager' => array_values(array_diff(config('permissions.roles.manager'), ['services.price']))]);
        $gerente = User::factory()->manager()->create();
        $base = ['name' => $this->servico->name, 'category_id' => $this->cat->id, 'duration_minutes' => 30, 'version' => $this->servico->lock_version];

        $this->actingAs($gerente, 'web')->get(route('panel.services.edit', $this->servico))->assertOk()->assertDontSee('name="price"', false);
        $this->actingAs($gerente, 'web')->put(route('panel.services.update', $this->servico), $base + ['price' => '1,00'])->assertForbidden();
        $this->assertSame(4500, $this->servico->fresh()->price_cents);

        // Sem o campo (como o formulario manda): edita o resto normalmente.
        $this->actingAs($gerente, 'web')->put(route('panel.services.update', $this->servico), array_merge($base, ['duration_minutes' => 45]))->assertSessionHasNoErrors();
        $this->assertSame(45, $this->servico->fresh()->duration_minutes);
        // Mesmo preco enviado de novo nao e alteracao.
        $this->actingAs($gerente, 'web')->put(route('panel.services.update', $this->servico), ['price' => '45,00', 'version' => $this->servico->fresh()->lock_version] + $base)->assertSessionHasNoErrors();
    }

    public function test_exibicao_no_site_exige_habilidade_propria(): void
    {
        Storage::fake('public');
        config(['permissions.roles.manager' => array_values(array_diff(config('permissions.roles.manager'), ['services.display', 'professionals.display']))]);
        $gerente = User::factory()->manager()->create();
        $base = ['name' => $this->servico->name, 'category_id' => $this->cat->id, 'duration_minutes' => 30, 'version' => $this->servico->lock_version];

        $this->actingAs($gerente, 'web')->put(route('panel.services.update', $this->servico), $base + ['is_public' => '0'])->assertForbidden();
        $this->actingAs($gerente, 'web')->put(route('panel.services.update', $this->servico), $base + ['image' => UploadedFile::fake()->image('a.jpg', 300, 300)])->assertForbidden();
        $this->actingAs($gerente, 'web')->post(route('panel.services.move', $this->servico), ['direction' => 'up'])->assertForbidden();
        $this->actingAs($gerente, 'web')->put(route('panel.professionals.update', $this->pro), ['display_name' => $this->pro->display_name, 'version' => $this->pro->lock_version, 'bio' => 'Invasão'])->assertForbidden();

        $this->assertTrue($this->servico->fresh()->is_public);
        $this->assertNull($this->servico->fresh()->image_path);
        $this->assertNull($this->pro->fresh()->bio);
    }

    // --- IDs manipulados ----------------------------------------------------------------------

    public function test_ids_manipulados_nao_dao_acesso_indevido(): void
    {
        $gerente = User::factory()->manager()->create();
        $excluido = Service::factory()->create(['category_id' => $this->cat->id]);
        $excluido->delete();

        // Registro excluido ou inexistente: 404 (nao reaparece pela URL).
        $this->actingAs($gerente, 'web')->get(route('panel.services.edit', $excluido->id))->assertNotFound();
        $this->actingAs($gerente, 'web')->post(route('panel.services.status', 999999), ['active' => 1])->assertNotFound();
        $this->actingAs($gerente, 'web')->get('/painel/profissionais/999999/editar')->assertNotFound();

        // Vincular servico excluido pelo id: recusado, nada gravado.
        $this->actingAs($gerente, 'web')->put(route('panel.professionals.services.update', $this->pro), ['services' => [$excluido->id]])->assertSessionHasErrors('services');
        $this->assertSame(0, $this->pro->services()->withTrashed()->count());

        // Categoria excluida pelo id no formulario do servico: recusada.
        $catExcluida = ServiceCategory::factory()->create();
        $catExcluida->delete();
        $this->actingAs($gerente, 'web')->post(route('panel.services.store'), ['name' => 'Y', 'category_id' => $catExcluida->id, 'duration_minutes' => 30, 'price' => '10,00'])
            ->assertSessionHasErrors('category_id');
    }

    public function test_campos_fora_do_formulario_sao_ignorados(): void
    {
        $gerente = User::factory()->manager()->create();

        $this->actingAs($gerente, 'web')->put(route('panel.professionals.update', $this->pro), [
            'display_name' => $this->pro->display_name, 'version' => $this->pro->lock_version,
            'commission_rate_bp' => 10000, 'is_active' => 0, 'sort_order' => -5, 'lock_version' => 99, 'slug' => 'hack',
        ])->assertSessionHasNoErrors();

        $p = $this->pro->fresh();
        $this->assertSame(0, CommissionRule::query()->count(), 'comissao tem tela e permissao proprias (Fase 7)');
        $this->assertTrue($p->is_active, 'ativar/desativar tem rota e permissao proprias');
        $this->assertNotSame('hack', $p->slug);
    }
}

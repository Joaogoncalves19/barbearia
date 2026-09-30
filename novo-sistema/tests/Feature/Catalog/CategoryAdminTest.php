<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Identity\Models\User;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Categorias: cadastro, edicao, ativacao, ordem, exclusao so quando vazia.
 */
class CategoryAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gerente = User::factory()->manager()->create();
    }

    public function test_cria_categoria_no_fim_da_ordem_com_slug_estavel(): void
    {
        ServiceCategory::factory()->create(['name' => 'Cabelo', 'sort_order' => 10]);

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.store'), ['name' => '  Barba   e  bigode ', 'description' => 'Tudo de barba.'])
            ->assertRedirect(route('panel.categories.index'));

        $c = ServiceCategory::query()->where('name', 'Barba e bigode')->firstOrFail();
        $this->assertSame('barba-e-bigode', $c->slug);
        $this->assertSame(20, $c->sort_order);
        $this->assertTrue($c->is_active);
    }

    public function test_nome_obrigatorio_e_unico_sem_diferenciar_maiusculas(): void
    {
        ServiceCategory::factory()->create(['name' => 'Cabelo']);

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.store'), ['name' => 'CABELO'])->assertSessionHasErrors('name');
        $this->assertSame(1, ServiceCategory::query()->count());
    }

    public function test_renomear_nao_muda_o_slug(): void
    {
        $c = ServiceCategory::factory()->create(['name' => 'Cabelo']);

        $this->actingAs($this->gerente, 'web')->put(route('panel.categories.update', $c), ['name' => 'Cabelos', 'version' => $c->lock_version])
            ->assertRedirect(route('panel.categories.index'));

        $this->assertSame('Cabelos', $c->fresh()->name);
        $this->assertSame('cabelo', $c->fresh()->slug);
    }

    public function test_edicao_simultanea_nao_sobrescreve_em_silencio(): void
    {
        $c = ServiceCategory::factory()->create(['name' => 'Cabelo']);
        $versaoVista = $c->lock_version;

        $this->actingAs($this->gerente, 'web')->put(route('panel.categories.update', $c), ['name' => 'Primeira', 'version' => $versaoVista]);
        $this->actingAs($this->gerente, 'web')->put(route('panel.categories.update', $c), ['name' => 'Segunda', 'version' => $versaoVista])
            ->assertSessionHasErrors('version');

        $this->assertSame('Primeira', $c->fresh()->name);
    }

    public function test_desativar_tira_os_servicos_de_novos_agendamentos_sem_apagar_nada(): void
    {
        $c = ServiceCategory::factory()->create();
        $s = Service::factory()->create(['category_id' => $c->id]);
        $this->assertTrue(Service::query()->bookable()->whereKey($s->id)->exists());

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.status', $c), ['active' => 0])->assertRedirect();

        $this->assertFalse($c->fresh()->is_active);
        $this->assertTrue($s->fresh()->is_active, 'o servico em si continua ativo');
        $this->assertFalse(Service::query()->bookable()->whereKey($s->id)->exists());

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.status', $c), ['active' => 1]);
        $this->assertTrue(Service::query()->bookable()->whereKey($s->id)->exists());
    }

    public function test_ordem_sobe_e_desce_sem_repetir_posicao(): void
    {
        [$a, $b, $c] = collect(['A', 'B', 'C'])->map(fn ($n, $i) => ServiceCategory::factory()->create(['name' => $n, 'sort_order' => ($i + 1) * 10]))->all();

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.move', $c), ['direction' => 'up']);
        $this->assertSame(['A', 'C', 'B'], ServiceCategory::query()->ordered()->pluck('name')->all());

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.move', $a), ['direction' => 'up']); // ja e o primeiro
        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.move', $a), ['direction' => 'down']);
        $this->assertSame(['C', 'A', 'B'], ServiceCategory::query()->ordered()->pluck('name')->all());
        $this->assertSame([10, 20, 30], ServiceCategory::query()->ordered()->pluck('sort_order')->all());

        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.move', $b), ['direction' => 'lado'])->assertSessionHasErrors('direction');
    }

    public function test_so_categoria_vazia_pode_ser_excluida(): void
    {
        $vazia = ServiceCategory::factory()->create();
        $comServicoExcluido = ServiceCategory::factory()->create();
        Service::factory()->create(['category_id' => $comServicoExcluido->id])->delete();

        $this->actingAs($this->gerente, 'web')->delete(route('panel.categories.destroy', $comServicoExcluido))->assertForbidden();
        $this->assertNotSoftDeleted($comServicoExcluido);

        $this->actingAs($this->gerente, 'web')->delete(route('panel.categories.destroy', $vazia))->assertRedirect(route('panel.categories.index'));
        $this->assertSoftDeleted($vazia);
    }

    public function test_alteracoes_vao_para_a_auditoria(): void
    {
        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.store'), ['name' => 'Cabelo']);
        $c = ServiceCategory::query()->firstOrFail();
        $this->actingAs($this->gerente, 'web')->post(route('panel.categories.status', $c), ['active' => 0]);

        $log = AuditLog::query()->where('auditable_type', 'ServiceCategory')->where('auditable_id', $c->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $log->pluck('action')->all());
        $this->assertSame($this->gerente->id, $log[1]->actor_id);
        $this->assertSame(['is_active' => false], $log[1]->new_values);
    }
}

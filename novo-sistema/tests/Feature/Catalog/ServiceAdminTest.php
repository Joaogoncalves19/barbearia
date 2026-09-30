<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Servicos: criacao, edicao, preco (atual x historico), duracao, ativacao,
 * ordem, exclusao so quando nunca usado, concorrencia e imagem.
 */
class ServiceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    private ServiceCategory $cabelo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gerente = User::factory()->manager()->create();
        $this->cabelo = ServiceCategory::factory()->create(['name' => 'Cabelo']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dados(array $extra = []): array
    {
        return $extra + ['name' => 'Corte degradê', 'category_id' => $this->cabelo->id, 'duration_minutes' => 45, 'price' => '55,00', 'description' => 'Máquina e tesoura.'];
    }

    private function editar(Service $s, array $extra = []): TestResponse
    {
        return $this->actingAs($this->gerente, 'web')->put(route('panel.services.update', $s), $extra + [
            'name' => $s->name, 'category_id' => $s->category_id, 'duration_minutes' => $s->duration_minutes, 'version' => $s->fresh()->lock_version,
        ]);
    }

    // --- Criacao e validacao ---------------------------------------------------------------

    public function test_cria_servico_com_preco_em_centavos_e_duracao_em_minutos(): void
    {
        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados())
            ->assertRedirect(route('panel.services.index'));

        $s = Service::query()->sole();
        $this->assertSame(5500, $s->price_cents);
        $this->assertSame(45, $s->duration_minutes);
        $this->assertSame('corte-degrade', $s->slug);
        $this->assertTrue($s->is_active);
        $this->assertSame('R$ 55,00', $s->price()->format());
        $this->assertSame('45 min', $s->durationLabel());
    }

    public function test_preco_aceita_formatos_brasileiros_sem_float(): void
    {
        foreach (['1.250,90' => 125090, '45' => 4500, '45,5' => 4550, 'R$ 30,00' => 3000, '19.99' => 1999] as $digitado => $centavos) {
            Service::query()->forceDelete();
            // (string): chave '45' vira inteiro no array do PHP; o formulario envia texto.
            $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['price' => (string) $digitado]))->assertSessionHasNoErrors();
            $this->assertSame($centavos, Service::query()->sole()->price_cents, $digitado);
        }
    }

    public function test_nao_permite_servico_sem_preco_valido(): void
    {
        foreach (['', 'abc', '0', '0,50', '-10,00', '10.000,01', '1,999'] as $preco) {
            $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['price' => $preco]))
                ->assertSessionHasErrors('price');
        }
        $this->assertSame(0, Service::query()->count());
    }

    public function test_nao_permite_duracao_invalida(): void
    {
        foreach ([0, -30, 7, 481, 'meia hora', ''] as $duracao) {
            $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['duration_minutes' => $duracao]))
                ->assertSessionHasErrors('duration_minutes');
        }
        $this->assertSame(0, Service::query()->count());
    }

    public function test_regras_de_preco_e_duracao_tambem_valem_fora_da_tela(): void
    {
        $this->expectException(DomainRuleViolation::class);
        Service::factory()->create(['duration_minutes' => 13]);
    }

    public function test_categoria_obrigatoria_existente_e_ativa(): void
    {
        $inativa = ServiceCategory::factory()->create(['is_active' => false]);

        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['category_id' => '']))->assertSessionHasErrors('category_id');
        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['category_id' => 999]))->assertSessionHasErrors('category_id');
        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['category_id' => $inativa->id]))->assertSessionHasErrors('category_id');
    }

    public function test_nome_duplicado_e_recusado(): void
    {
        Service::factory()->create(['name' => 'Corte degradê']);

        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['name' => 'CORTE DEGRADÊ']))
            ->assertSessionHasErrors('name');
    }

    // --- Preco atual x historico ------------------------------------------------------------

    public function test_alterar_o_preco_atual_nao_altera_agendamentos_existentes(): void
    {
        $s = Service::factory()->create(['price_cents' => 4500, 'category_id' => $this->cabelo->id]);
        $ag = Appointment::factory()->create();
        $item = AppointmentItem::query()->create([
            'appointment_id' => $ag->id, 'item_type' => ItemType::Service, 'service_id' => $s->id, 'name' => $s->name,
            'quantity' => 1, 'unit_price_cents' => 4500, 'total_cents' => 4500, 'duration_minutes' => 30, 'price_source' => PriceSource::CatalogAtBooking,
        ]);

        $this->editar($s, ['price' => '60,00'])->assertRedirect(route('panel.services.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'vale para agendamentos novos'));

        $this->assertSame(6000, $s->fresh()->price_cents);
        $this->assertSame(4500, $item->fresh()->unit_price_cents, 'o agendamento guarda o preco do momento');
        $this->assertSame(4500, $item->fresh()->total_cents);
    }

    public function test_historico_de_preco_vem_da_auditoria(): void
    {
        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados(['price' => '45,00']));
        $s = Service::query()->sole();
        $this->editar($s, ['price' => '50,00']);
        $this->editar($s, ['price' => '50,00', 'description' => 'mudou so a descricao']);

        $this->actingAs($this->gerente, 'web')->get(route('panel.services.edit', $s))
            ->assertOk()->assertSee('Histórico de preço')->assertSeeInOrder(['R$ 45,00', 'R$ 50,00']);

        $alteracoes = AuditLog::query()->where('auditable_type', 'Service')->where('action', 'updated')->get()
            ->filter(fn ($l) => isset($l->new_values['price_cents']));
        $this->assertCount(1, $alteracoes);
        $this->assertSame(['price_cents' => 4500], array_intersect_key($alteracoes->first()->old_values, ['price_cents' => 1]));
        $this->assertSame($this->gerente->id, $alteracoes->first()->actor_id);
    }

    public function test_alterar_duracao_e_auditado(): void
    {
        $s = Service::factory()->create(['duration_minutes' => 30, 'category_id' => $this->cabelo->id]);

        $this->editar($s, ['duration_minutes' => 60]);

        $this->assertSame(60, $s->fresh()->duration_minutes);
        $log = AuditLog::query()->where('auditable_type', 'Service')->where('action', 'updated')->latest('id')->first();
        $this->assertSame(30, $log->old_values['duration_minutes']);
        $this->assertSame(60, $log->new_values['duration_minutes']);
    }

    public function test_dois_administradores_alterando_o_preco_ao_mesmo_tempo(): void
    {
        $s = Service::factory()->create(['price_cents' => 4500, 'category_id' => $this->cabelo->id]);
        $vista = $s->lock_version;
        $base = ['name' => $s->name, 'category_id' => $s->category_id, 'duration_minutes' => $s->duration_minutes, 'version' => $vista];

        $this->actingAs($this->gerente, 'web')->put(route('panel.services.update', $s), $base + ['price' => '50,00'])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->owner()->create(), 'web')->put(route('panel.services.update', $s), $base + ['price' => '70,00'])
            ->assertSessionHasErrors('version');

        $this->assertSame(5000, $s->fresh()->price_cents, 'a segunda gravacao nao sobrescreve em silencio');
    }

    // --- Ativacao, ordem, exclusao -----------------------------------------------------------

    public function test_desativar_preserva_o_servico_e_o_historico(): void
    {
        $s = Service::factory()->create(['category_id' => $this->cabelo->id]);
        $item = AppointmentItem::query()->create([
            'appointment_id' => Appointment::factory()->create()->id, 'item_type' => ItemType::Service, 'service_id' => $s->id, 'name' => $s->name,
            'quantity' => 1, 'unit_price_cents' => 4500, 'total_cents' => 4500, 'duration_minutes' => 30, 'price_source' => PriceSource::CatalogAtBooking,
        ]);

        $this->actingAs($this->gerente, 'web')->post(route('panel.services.status', $s), ['active' => 0])->assertRedirect();

        $this->assertFalse($s->fresh()->is_active);
        $this->assertNotSoftDeleted($s);
        $this->assertSame($s->id, $item->fresh()->service_id);
        $this->assertFalse(Service::query()->bookable()->whereKey($s->id)->exists());
        $this->assertFalse(Service::query()->shownPublicly()->whereKey($s->id)->exists());
    }

    public function test_ordem_vale_dentro_da_categoria(): void
    {
        $barba = ServiceCategory::factory()->create();
        $a = Service::factory()->create(['name' => 'A', 'category_id' => $this->cabelo->id, 'sort_order' => 10]);
        $b = Service::factory()->create(['name' => 'B', 'category_id' => $this->cabelo->id, 'sort_order' => 20]);
        $x = Service::factory()->create(['name' => 'X', 'category_id' => $barba->id, 'sort_order' => 15]);

        $this->actingAs($this->gerente, 'web')->post(route('panel.services.move', $b), ['direction' => 'up']);

        $this->assertSame(['B', 'A'], Service::query()->where('category_id', $this->cabelo->id)->ordered()->pluck('name')->all());
        $this->assertSame(15, $x->fresh()->sort_order, 'outra categoria nao muda');
    }

    public function test_servico_usado_nao_pode_ser_excluido_so_desativado(): void
    {
        $usadoEmAgendamento = Service::factory()->create(['category_id' => $this->cabelo->id]);
        AppointmentItem::query()->create([
            'appointment_id' => Appointment::factory()->create()->id, 'item_type' => ItemType::Service, 'service_id' => $usadoEmAgendamento->id,
            'name' => 'x', 'quantity' => 1, 'unit_price_cents' => 100, 'total_cents' => 100, 'duration_minutes' => 30, 'price_source' => PriceSource::CatalogAtBooking,
        ]);
        $usadoEmCombo = Service::factory()->create(['category_id' => $this->cabelo->id]);
        Package::factory()->create()->items()->create(['service_id' => $usadoEmCombo->id, 'quantity' => 1]);

        foreach ([$usadoEmAgendamento, $usadoEmCombo] as $s) {
            $this->actingAs($this->gerente, 'web')->delete(route('panel.services.destroy', $s))->assertForbidden();
            $this->assertNotSoftDeleted($s);
        }
    }

    public function test_servico_nunca_usado_pode_ser_excluido_e_sai_dos_profissionais(): void
    {
        $s = Service::factory()->create(['category_id' => $this->cabelo->id]);
        $p = Professional::factory()->create();
        $p->services()->attach($s->id);

        $this->actingAs($this->gerente, 'web')->delete(route('panel.services.destroy', $s))->assertRedirect(route('panel.services.index'));

        $this->assertSoftDeleted($s);
        $this->assertSame(0, $p->services()->count());
    }

    // --- Imagem ------------------------------------------------------------------------------

    public function test_imagem_vai_para_o_disco_de_midia_e_a_antiga_e_apagada(): void
    {
        Storage::fake('public');
        $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados() + ['image' => UploadedFile::fake()->image('corte.jpg', 800, 600)]);
        $s = Service::query()->sole();
        $primeira = $s->image_path;
        Storage::disk('public')->assertExists($primeira);
        $this->assertStringStartsWith('services/', $primeira);
        $this->assertStringNotContainsString('corte', $primeira, 'nome gerado, nunca o do usuario');

        $this->editar($s, ['image' => UploadedFile::fake()->image('outra.png', 400, 400)]);
        Storage::disk('public')->assertMissing($primeira);
        Storage::disk('public')->assertExists($s->fresh()->image_path);

        $this->editar($s, ['remove_image' => '1']);
        $this->assertNull($s->fresh()->image_path);
    }

    public function test_so_imagem_valida_e_aceita(): void
    {
        Storage::fake('public');

        foreach ([UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'), UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('mini.jpg', 50, 50)] as $arquivo) {
            $this->actingAs($this->gerente, 'web')->post(route('panel.services.store'), $this->dados() + ['image' => $arquivo])->assertSessionHasErrors('image');
        }
        $this->assertSame(0, Service::query()->count());
    }
}

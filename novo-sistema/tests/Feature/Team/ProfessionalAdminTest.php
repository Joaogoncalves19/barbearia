<?php

namespace Tests\Feature\Team;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Profissionais: cadastro, edicao, ativacao, conta vinculada (opcional),
 * servicos executados, historico preservado e foto.
 */
class ProfessionalAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gerente = User::factory()->manager()->create();
    }

    private function editar(Professional $p, array $extra = []): TestResponse
    {
        $p->refresh();

        return $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.update', $p), $extra + [
            'display_name' => $p->display_name, 'user_id' => $p->user_id, 'version' => $p->lock_version,
        ]);
    }

    public function test_cadastra_profissional_sem_conta_de_acesso(): void
    {
        $antes = User::query()->count();

        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.store'), [
            'display_name' => 'João Navalha', 'user_id' => '', 'is_bookable' => '1',
            'headline' => 'Degradê e barba', 'bio' => 'Dez anos de tesoura.', 'is_public' => '1', 'is_featured' => '0',
        ])->assertRedirect();

        $p = Professional::query()->sole();
        $this->assertNull($p->user_id, 'profissional nao precisa de login');
        $this->assertSame($antes, User::query()->count(), 'nenhuma conta criada sozinha');
        $this->assertSame('joao-navalha', $p->slug);
        $this->assertTrue($p->is_active);
        $this->assertTrue($p->isBookable());
        $this->assertSame('Degradê e barba', $p->headline);
    }

    public function test_vincula_conta_existente_e_nao_deixa_a_mesma_conta_em_dois(): void
    {
        $conta = User::factory()->role(StaffRole::Professional)->create();
        $a = Professional::factory()->create();
        $b = Professional::factory()->create();

        $this->editar($a, ['user_id' => $conta->id])->assertSessionHasNoErrors();
        $this->assertSame($conta->id, $a->fresh()->user_id);
        $this->assertTrue($conta->fresh()->professional->is($a));

        $this->editar($b, ['user_id' => $conta->id])->assertSessionHasErrors('user_id');
        $this->assertNull($b->fresh()->user_id);

        $this->editar($a, ['user_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->user_id, 'desvincular e permitido');
        $this->assertNotNull($conta->fresh(), 'a conta continua existindo');
    }

    public function test_desativar_preserva_historico_e_nao_mexe_na_conta(): void
    {
        $conta = User::factory()->role(StaffRole::Professional)->create();
        $p = Professional::factory()->create(['user_id' => $conta->id, 'display_name' => 'Carlos']);
        $ag = Appointment::factory()->create(['professional_id' => $p->id, 'professional_name' => 'Carlos']);

        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.status', $p), ['active' => 0])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'continua ativa'));

        $p->refresh();
        $this->assertFalse($p->is_active);
        $this->assertNotSoftDeleted($p);
        $this->assertSame($p->id, $ag->fresh()->professional_id, 'atendimento antigo continua ligado');
        $this->assertSame('Carlos', $ag->fresh()->professional_name);
        $this->assertTrue($conta->fresh()->is_active, 'usuario e profissional sao conceitos separados');
        $this->assertFalse(Professional::query()->bookable()->whereKey($p->id)->exists());
        $this->assertFalse(Professional::query()->shownPublicly()->whereKey($p->id)->exists());
    }

    public function test_renomear_nao_reescreve_o_nome_fotografado_nos_agendamentos(): void
    {
        $p = Professional::factory()->create(['display_name' => 'Carlos']);
        $ag = Appointment::factory()->create(['professional_id' => $p->id, 'professional_name' => 'Carlos']);

        $this->editar($p, ['display_name' => 'Carlos Tesoura'])->assertSessionHasNoErrors();

        $this->assertSame('Carlos Tesoura', $p->fresh()->display_name);
        $this->assertSame('Carlos', $ag->fresh()->professional_name);
    }

    public function test_nome_de_exibicao_obrigatorio_e_unico(): void
    {
        Professional::factory()->create(['display_name' => 'Carlos']);

        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.store'), ['display_name' => ''])->assertSessionHasErrors('display_name');
        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.store'), ['display_name' => 'carlos'])->assertSessionHasErrors('display_name');
    }

    public function test_edicao_simultanea_e_detectada(): void
    {
        $p = Professional::factory()->create(['display_name' => 'Carlos']);
        $vista = $p->fresh()->lock_version;

        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.update', $p), ['display_name' => 'Primeiro', 'version' => $vista]);
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.update', $p), ['display_name' => 'Segundo', 'version' => $vista])
            ->assertSessionHasErrors('version');

        $this->assertSame('Primeiro', $p->fresh()->display_name);
    }

    // --- Servicos executados -------------------------------------------------------------------

    public function test_define_os_servicos_que_o_profissional_executa_e_audita(): void
    {
        [$corte, $barba, $combo] = collect(['Corte', 'Barba', 'Corte + Barba'])->map(fn ($n) => Service::factory()->create(['name' => $n]))->all();
        $joao = Professional::factory()->create(['display_name' => 'João']);
        $carlos = Professional::factory()->create(['display_name' => 'Carlos']);

        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $joao), ['services' => [$corte->id, $barba->id, $combo->id]])->assertRedirect();
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $carlos), ['services' => [$corte->id, $barba->id]])->assertRedirect();

        $this->assertEqualsCanonicalizing([$corte->id, $barba->id, $combo->id], $joao->services()->pluck('services.id')->all());
        $this->assertEqualsCanonicalizing([$corte->id, $barba->id], $carlos->services()->pluck('services.id')->all());

        $diretorio = app(ProfessionalDirectory::class);
        $this->assertSame(['João'], $diretorio->bookableFor($combo)->pluck('display_name')->all(), 'nao assume que todos fazem tudo');
        $this->assertEqualsCanonicalizing(['João', 'Carlos'], $diretorio->bookableFor($corte)->pluck('display_name')->all());

        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $carlos), ['services' => [$corte->id]]);
        $log = AuditLog::query()->where('action', 'professional.services_changed')->where('auditable_id', $carlos->id)->latest('id')->first();
        $this->assertSame((string) $barba->id, $log->new_values['removidos']);
        $this->assertSame($this->gerente->id, $log->actor_id);
    }

    public function test_nao_vincula_servico_inativo_inexistente_ou_duplicado(): void
    {
        $ativo = Service::factory()->create();
        $inativo = Service::factory()->create(['is_active' => false]);
        $p = Professional::factory()->create();

        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $p), ['services' => [$ativo->id, $inativo->id]])
            ->assertSessionHasErrors('services');
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $p), ['services' => [$ativo->id, 999999]])
            ->assertSessionHasErrors('services');
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $p), ['services' => [$ativo->id, $ativo->id]])
            ->assertSessionHasErrors('services.0');

        $this->assertSame(0, $p->services()->count(), 'nada gravado pela metade');
    }

    public function test_vinculo_com_servico_que_foi_desativado_fica_guardado(): void
    {
        $a = Service::factory()->create();
        $b = Service::factory()->create();
        $p = Professional::factory()->create();
        $p->services()->attach([$a->id, $b->id]);
        $b->update(['is_active' => false]);

        // A tela so mostra os ativos; salvar sem o inativo nao o remove.
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $p), ['services' => [$a->id]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $p->services()->pluck('services.id')->all());
        $this->assertSame([$a->id], app(ProfessionalDirectory::class)->bookableServicesOf($p)->pluck('id')->all());

        $b->update(['is_active' => true]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], app(ProfessionalDirectory::class)->bookableServicesOf($p)->pluck('id')->all());
    }

    public function test_desmarcar_todos_limpa_os_servicos(): void
    {
        $p = Professional::factory()->create();
        $p->services()->attach(Service::factory()->create()->id);

        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.services.update', $p), [])->assertSessionHasNoErrors();

        $this->assertSame(0, $p->services()->count());
    }

    // --- Agenda futura: quem pode ser escolhido -------------------------------------------------

    public function test_diretorio_so_oferece_quem_pode_receber_agendamento(): void
    {
        $cat = ServiceCategory::factory()->create();
        $s = Service::factory()->create(['category_id' => $cat->id]);
        $ativo = Professional::factory()->create(['display_name' => 'Ativo']);
        $inativo = Professional::factory()->create(['display_name' => 'Inativo', 'is_active' => false]);
        $naoAgendavel = Professional::factory()->create(['display_name' => 'Treinando', 'is_bookable' => false]);
        foreach ([$ativo, $inativo, $naoAgendavel] as $p) {
            $p->services()->attach($s->id);
        }

        $dir = app(ProfessionalDirectory::class);
        $this->assertSame(['Ativo'], $dir->bookableFor($s)->pluck('display_name')->all());

        $cat->update(['is_active' => false]);
        $this->assertSame([], $dir->bookableFor($s)->all(), 'categoria inativa: servico nao agendavel');
    }

    public function test_equipe_publica_respeita_ordem_e_visibilidade(): void
    {
        Professional::factory()->create(['display_name' => 'B', 'sort_order' => 20]);
        Professional::factory()->create(['display_name' => 'A', 'sort_order' => 10]);
        Professional::factory()->create(['display_name' => 'Oculto', 'is_public' => false, 'sort_order' => 5]);
        Professional::factory()->create(['display_name' => 'Saiu', 'is_active' => false, 'sort_order' => 1]);

        $this->assertSame(['A', 'B'], app(ProfessionalDirectory::class)->publicTeam()->pluck('display_name')->all());
    }

    // --- Foto ------------------------------------------------------------------------------------

    public function test_foto_no_disco_de_midia(): void
    {
        Storage::fake('public');
        $p = Professional::factory()->create();

        $this->editar($p, ['photo' => UploadedFile::fake()->image('eu.jpg', 600, 800)])->assertSessionHasNoErrors();

        $caminho = $p->fresh()->photo_path;
        Storage::disk('public')->assertExists($caminho);
        $this->assertStringStartsWith('professionals/', $caminho);
        $this->assertStringContainsString('/storage/professionals/', (string) $p->fresh()->photoUrl());
    }

    public function test_ordem_da_equipe(): void
    {
        $a = Professional::factory()->create(['display_name' => 'A', 'sort_order' => 10]);
        Professional::factory()->create(['display_name' => 'B', 'sort_order' => 20]);

        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.move', $a), ['direction' => 'down']);

        $this->assertSame(['B', 'A'], Professional::query()->ordered()->pluck('display_name')->all());
    }

    public function test_usuario_criado_com_papel_profissional_ganha_ficha_fora_do_site(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('panel.users.store'), ['name' => 'Novo Barbeiro', 'username' => 'novo', 'role' => 'professional', 'temporary_password' => 'Provisoria123']);

        $p = User::query()->where('username', 'novo')->firstOrFail()->professional;
        $this->assertNotNull($p);
        $this->assertFalse($p->is_public);
        $this->assertFalse($p->isBookable());
    }
}

<?php

namespace Tests\Feature\Security;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Acesso VERTICAL (papel x funcao) e separacao entre as contas da equipe e
 * as dos clientes.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_vai_para_o_login_certo_de_cada_area(): void
    {
        $this->get(route('panel.home'))->assertRedirect(route('staff.login'));
        $this->get(route('panel.users.index'))->assertRedirect(route('staff.login'));
        $this->get(route('account.home'))->assertRedirect(route('customer.login'));
        $this->get(route('account.profile.edit'))->assertRedirect(route('customer.login'));
    }

    public function test_todos_os_papeis_da_equipe_acessam_o_painel(): void
    {
        foreach (StaffRole::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'web')
                ->get(route('panel.home'))
                ->assertOk();
        }
    }

    public function test_sem_a_permissao_da_rota_o_acesso_e_negado_com_403(): void
    {
        config(['permissions.roles.reception' => []]);

        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('panel.home'))
            ->assertForbidden()
            ->assertSee('Acesso não permitido');
    }

    public function test_usuario_desativado_perde_o_acesso_na_requisicao_seguinte(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get(route('panel.home'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh(), 'web')->get(route('panel.home'))->assertRedirect(route('staff.login'));
        $this->assertGuest('web');
    }

    public function test_papel_rebaixado_perde_a_permissao_na_hora(): void
    {
        $user = User::factory()->owner()->create();
        $this->actingAs($user, 'web')->get(route('panel.audit.index'))->assertOk();

        $user->forceFill(['role' => StaffRole::Reception])->save();

        $this->actingAs($user->fresh(), 'web')->get(route('panel.audit.index'))->assertForbidden();
    }

    public function test_habilidade_nao_declarada_e_negada_ate_para_o_proprietario(): void
    {
        $dono = User::factory()->owner()->create();

        $this->assertTrue(Gate::forUser($dono)->allows('users.manage'));
        $this->assertFalse(Gate::forUser($dono)->allows('habilidade.inventada'));
    }

    public function test_conteudo_restrito_so_aparece_para_quem_tem_permissao(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get(route('panel.home'))
            ->assertDontSee('Saúde do sistema')->assertDontSee('Usuários');
        $this->actingAs(User::factory()->manager()->create(), 'web')->get(route('panel.home'))
            ->assertSee('Saúde do sistema');
        $this->actingAs(User::factory()->owner()->create(), 'web')->get(route('panel.home'))
            ->assertSee(route('panel.users.index'));
    }

    // --- Gestao de usuarios: so o proprietario -----------------------------------------------

    public function test_so_o_proprietario_gerencia_usuarios(): void
    {
        foreach ([StaffRole::Manager, StaffRole::Reception, StaffRole::Finance, StaffRole::Professional] as $role) {
            $user = User::factory()->role($role)->create();
            $alvo = User::factory()->create();

            $this->actingAs($user, 'web')->get(route('panel.users.index'))->assertForbidden();
            $this->actingAs($user, 'web')->get(route('panel.users.create'))->assertForbidden();
            $this->actingAs($user, 'web')->post(route('panel.users.store'), [])->assertForbidden();
            $this->actingAs($user, 'web')->put(route('panel.users.update', $alvo), ['role' => 'owner'])->assertForbidden();
            $this->actingAs($user, 'web')->post(route('panel.users.temporary-password', $alvo), ['temporary_password' => 'Invasao123'])->assertForbidden();
        }
    }

    public function test_gestao_de_usuarios_pede_a_senha_de_novo(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->get(route('panel.users.index'))->assertRedirect(route('panel.password.confirm'));

        $this->actingAs($dono, 'web')->post(route('panel.password.confirm.store'), ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->actingAs($dono, 'web')->post(route('panel.password.confirm.store'), ['password' => 'password'])->assertRedirect();

        $this->actingAs($dono, 'web')->get(route('panel.users.index'))->assertOk();
    }

    public function test_proprietario_cria_usuario_com_senha_provisoria(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('panel.users.store'), [
                'name' => 'Nova Recepcionista', 'username' => 'Nova.Recepcao', 'email' => '',
                'role' => 'reception', 'temporary_password' => 'Provisoria123',
            ])->assertRedirect(route('panel.users.index'));

        $novo = User::query()->where('username', 'nova.recepcao')->firstOrFail();
        $this->assertSame(StaffRole::Reception, $novo->role);
        $this->assertTrue($novo->must_change_password);
        $this->assertNull($novo->email);
    }

    public function test_usuario_com_papel_profissional_ganha_a_ficha_de_profissional(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('panel.users.store'), ['name' => 'Barbeiro Ficticio', 'username' => 'barbeiro', 'role' => 'professional', 'temporary_password' => 'Provisoria123']);

        $this->assertNotNull(User::query()->where('username', 'barbeiro')->firstOrFail()->professional);
    }

    public function test_usuario_nao_pode_ter_arroba_nem_repetir(): void
    {
        $dono = User::factory()->owner()->create();
        User::factory()->create(['username' => 'repetido']);
        $sessao = ['auth.password_confirmed_at' => time()];

        $this->actingAs($dono, 'web')->withSession($sessao)
            ->post(route('panel.users.store'), ['name' => 'X', 'username' => 'x@y', 'role' => 'reception', 'temporary_password' => 'Provisoria123'])
            ->assertSessionHasErrors('username');
        $this->actingAs($dono, 'web')->withSession($sessao)
            ->post(route('panel.users.store'), ['name' => 'X', 'username' => 'REPETIDO', 'role' => 'reception', 'temporary_password' => 'Provisoria123'])
            ->assertSessionHasErrors('username');
    }

    public function test_ninguem_muda_o_proprio_papel_nem_se_desativa(): void
    {
        $dono = User::factory()->owner()->create(['username' => 'dono']);
        User::factory()->owner()->create(); // ha outro proprietario: nao e a regra do ultimo

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('panel.users.update', $dono), ['name' => 'Dono', 'username' => 'dono', 'role' => 'reception'])
            ->assertForbidden();
        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('panel.users.update', $dono), ['name' => 'Dono', 'username' => 'dono', 'is_active' => '0'])
            ->assertForbidden();

        // Mudar so o proprio nome continua permitido.
        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('panel.users.update', $dono), ['name' => 'Dono Renomeado', 'username' => 'dono'])
            ->assertRedirect(route('panel.users.index'));
        $this->assertSame(StaffRole::Owner, $dono->fresh()->role);
        $this->assertSame('Dono Renomeado', $dono->fresh()->name);
    }

    public function test_sempre_sobra_um_proprietario_ativo(): void
    {
        $dono = User::factory()->owner()->create();
        $outroDono = User::factory()->owner()->create(['username' => 'outro']);
        $sessao = ['auth.password_confirmed_at' => time()];

        // Rebaixar o outro: permitido (ainda sobra $dono).
        $this->actingAs($dono, 'web')->withSession($sessao)
            ->put(route('panel.users.update', $outroDono), ['name' => 'Outro', 'username' => 'outro', 'role' => 'manager', 'is_active' => '1'])
            ->assertRedirect(route('panel.users.index'));

        // Unico proprietario ativo agora e $dono; e ele nao pode se rebaixar (policy).
        $this->assertSame(1, User::query()->where('role', 'owner')->where('is_active', true)->count());
    }

    public function test_senha_provisoria_derruba_as_sessoes_da_pessoa(): void
    {
        $dono = User::factory()->owner()->create();
        $alvo = User::factory()->create();
        $this->actingAs($alvo, 'web')->get(route('panel.home'))->assertOk();
        $sessaoDoAlvo = session()->all();

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('panel.users.temporary-password', $alvo), ['temporary_password' => 'Provisoria123'])
            ->assertRedirect(route('panel.users.edit', $alvo));

        $this->assertTrue($alvo->fresh()->must_change_password);
        $this->withSession($sessaoDoAlvo)->actingAs($alvo->fresh(), 'web')->get(route('panel.home'))->assertRedirect(route('staff.login'));
    }

    public function test_proprietario_nao_define_senha_provisoria_para_si(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('panel.users.temporary-password', $dono), ['temporary_password' => 'Provisoria123'])
            ->assertForbidden();
    }

    // --- Equipe x cliente: guards separados --------------------------------------------------

    public function test_sessao_de_cliente_nao_abre_o_painel(): void
    {
        $cliente = Customer::factory()->create();

        $this->actingAs($cliente, 'customer')->get(route('panel.home'))->assertRedirect(route('staff.login'));
        $this->actingAs($cliente, 'customer')->get(route('panel.users.index'))->assertRedirect(route('staff.login'));
    }

    public function test_sessao_da_equipe_nao_abre_a_area_do_cliente(): void
    {
        $dono = User::factory()->owner()->create();

        $this->actingAs($dono, 'web')->get(route('account.home'))->assertRedirect(route('customer.login'));
    }

    public function test_cliente_nao_recebe_nenhuma_habilidade_da_equipe_e_vice_versa(): void
    {
        $cliente = Customer::factory()->create();
        $dono = User::factory()->owner()->create();

        foreach (array_keys(config('permissions.abilities')) as $habilidade) {
            $this->assertFalse(Gate::forUser($cliente)->allows($habilidade), "cliente com {$habilidade}");
        }
        $this->assertTrue(Gate::forUser($cliente)->allows('account.access'));
        $this->assertFalse(Gate::forUser($dono)->allows('account.access'));
    }

    public function test_cliente_consultando_politica_da_equipe_e_negado_sem_erro(): void
    {
        $cliente = Customer::factory()->create();

        $this->assertFalse(Gate::forUser($cliente)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($cliente)->allows('update', User::factory()->create()));
    }
}

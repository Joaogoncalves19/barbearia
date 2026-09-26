<?php

namespace Tests\Feature\Security;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get(route('panel.home'))->assertRedirect(route('login'));
    }

    public function test_todos_os_papeis_da_equipe_acessam_o_painel(): void
    {
        foreach (StaffRole::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('panel.home'))
                ->assertOk();
        }
    }

    public function test_sem_a_permissao_da_rota_o_acesso_e_negado(): void
    {
        config(['permissions.roles.reception' => []]);

        $this->actingAs(User::factory()->create())
            ->get(route('panel.home'))
            ->assertForbidden()
            ->assertSee('Acesso não permitido');
    }

    public function test_usuario_desativado_perde_o_acesso_na_requisicao_seguinte(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('panel.home'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->get(route('panel.home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_habilidade_nao_declarada_e_negada_ate_para_o_proprietario(): void
    {
        $dono = User::factory()->owner()->create();

        $this->assertTrue(Gate::forUser($dono)->allows('users.manage'));
        $this->assertFalse(Gate::forUser($dono)->allows('habilidade.inventada'));
    }

    public function test_conteudo_restrito_so_aparece_para_quem_tem_permissao(): void
    {
        $this->actingAs(User::factory()->create())->get(route('panel.home'))->assertDontSee('Saúde do sistema');
        $this->actingAs(User::factory()->manager()->create())->get(route('panel.home'))->assertSee('Saúde do sistema');
    }
}

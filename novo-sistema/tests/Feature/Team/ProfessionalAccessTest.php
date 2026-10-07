<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 12.5 (pedido do dono, como no sistema antigo): usuario e senha do
 * profissional criados no PROPRIO cadastro do profissional. So quem gerencia
 * usuarios (proprietario), com a senha reconfirmada, as mesmas regras da tela
 * Usuarios (senha provisoria, troca no primeiro acesso, usuario unico) e tudo
 * numa transacao so.
 */
class ProfessionalAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    private User $gerente;

    private const SENHA = 'Provisoria2026x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dono = User::factory()->owner()->create();
        $this->gerente = User::factory()->manager()->create();
    }

    /** Proprietario com a senha reconfirmada agora. */
    private function donoConfirmado(): static
    {
        return $this->actingAs($this->dono, 'web')->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_proprietario_cadastra_o_profissional_ja_com_usuario_e_senha(): void
    {
        $this->donoConfirmado()->get(route('panel.professionals.create'))->assertOk()
            ->assertSee('Acesso ao painel')->assertSee('Usuário (para entrar)')->assertSee('Senha provisória');

        $r = $this->donoConfirmado()->post(route('panel.professionals.store'), [
            'display_name' => 'Carlos Navalha', 'is_bookable' => '1',
            'access_username' => 'Carlos', 'access_password' => self::SENHA, 'access_email' => 'carlos@exemplo.test',
        ]);

        $p = Professional::query()->where('display_name', 'Carlos Navalha')->sole();
        $r->assertRedirect(route('panel.professionals.services.edit', $p))->assertSessionHas('status');
        $u = $p->user;
        $this->assertNotNull($u);
        $this->assertSame(['carlos', 'carlos@exemplo.test', StaffRole::Professional, true, true], [$u->username, $u->email, $u->role, $u->is_active, $u->must_change_password]);
        $this->assertTrue(Hash::check(self::SENHA, $u->password));
        $this->assertSame(1, Professional::query()->count(), 'sem ficha duplicada');
        $this->assertSame(1, AuditLog::query()->where('action', 'user.created')->where('auditable_id', $u->id)->count());
        $this->assertStringNotContainsString(self::SENHA, (string) json_encode(AuditLog::query()->get()->toArray()));

        // Entra com o que foi cadastrado e e obrigado a trocar a senha.
        auth('web')->logout();
        $this->post(route('staff.login.attempt'), ['identifier' => 'carlos', 'password' => self::SENHA])->assertRedirect();
        $this->get(route('pro.today'))->assertRedirect(route('panel.password.edit'));
    }

    public function test_sem_reconfirmar_a_senha_pede_a_senha_e_nao_cria_nada(): void
    {
        $this->actingAs($this->dono, 'web')->get(route('panel.professionals.create'))->assertRedirect(route('panel.password.confirm'));
        $this->actingAs($this->dono, 'web')->post(route('panel.professionals.store'), [
            'display_name' => 'Sem Confirmar', 'access_username' => 'semconfirmar', 'access_password' => self::SENHA,
        ])->assertForbidden();
        $this->assertSame(0, Professional::query()->count());
        $this->assertNull(User::query()->where('username', 'semconfirmar')->first());
    }

    public function test_gerente_cadastra_o_profissional_mas_nao_cria_login(): void
    {
        $this->actingAs($this->gerente, 'web')->get(route('panel.professionals.create'))->assertOk()
            ->assertDontSee('Acesso ao painel')->assertSee('Conta de acesso ao sistema');

        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.store'), [
            'display_name' => 'Tentativa Gerente', 'access_username' => 'tentativa', 'access_password' => self::SENHA,
        ])->assertForbidden();
        $this->assertSame(0, Professional::query()->count());
        $this->assertNull(User::query()->where('username', 'tentativa')->first());

        // Sem os campos de acesso, cadastra normalmente (como antes).
        $this->actingAs($this->gerente, 'web')->post(route('panel.professionals.store'), ['display_name' => 'Só Ficha'])->assertRedirect();
        $this->assertNull(Professional::query()->sole()->user_id);
    }

    public function test_validacao_e_nada_fica_gravado_pela_metade(): void
    {
        User::factory()->create(['username' => 'ocupado']);
        $existente = User::factory()->role(StaffRole::Professional)->create(['username' => 'livre']);

        $casos = [
            'usuario repetido' => [['access_username' => 'ocupado', 'access_password' => self::SENHA], 'access_username'],
            'sem senha' => [['access_username' => 'novo'], 'access_password'],
            'senha fraca' => [['access_username' => 'novo', 'access_password' => 'curta'], 'access_password'],
            'senha sem usuario' => [['access_password' => self::SENHA], 'access_username'],
            'criar e ligar ao mesmo tempo' => [['access_username' => 'novo', 'access_password' => self::SENHA, 'user_id' => $existente->id], 'access_username'],
        ];
        foreach ($casos as $nome => [$campos, $erro]) {
            $r = $this->donoConfirmado()->post(route('panel.professionals.store'), ['display_name' => 'Validação '.$nome] + $campos);
            $r->assertSessionHasErrors($erro);
            $this->assertArrayNotHasKey('access_password', (array) session()->getOldInput(), "{$nome}: a senha nao volta para a sessao");
        }
        $this->assertSame(0, Professional::query()->count());
        $this->assertNull(User::query()->where('username', 'novo')->first());
    }

    public function test_editar_cria_acesso_para_quem_nao_tinha_e_troca_a_senha_de_quem_tem(): void
    {
        $semConta = Professional::factory()->create(['display_name' => 'Sem Conta']);
        $this->donoConfirmado()->put(route('panel.professionals.update', $semConta), [
            'display_name' => 'Sem Conta', 'version' => $semConta->lock_version,
            'access_username' => 'semconta', 'access_password' => self::SENHA,
        ])->assertRedirect(route('panel.professionals.index'));
        $u = $semConta->fresh()->user;
        $this->assertSame(['semconta', StaffRole::Professional], [$u?->username, $u?->role]);

        // Ja tem conta: mostra o usuario e permite nova senha provisoria (nao cria outro login).
        $p = $semConta->fresh();
        $this->donoConfirmado()->get(route('panel.professionals.edit', $p))->assertOk()->assertSee('semconta')->assertSee('Nova senha provisória')->assertDontSee('Usuário (para entrar)');
        $this->donoConfirmado()->put(route('panel.professionals.update', $p), [
            'display_name' => 'Sem Conta', 'version' => $p->lock_version, 'user_id' => $u->id,
            'access_username' => 'outro', 'access_password' => self::SENHA,
        ])->assertSessionHasErrors('access_username');
        $this->donoConfirmado()->put(route('panel.professionals.update', $p), [
            'display_name' => 'Sem Conta', 'version' => $p->lock_version, 'user_id' => $u->id,
            'access_reset_password' => 'NovaSenha2026y',
        ])->assertRedirect(route('panel.professionals.index'));
        $u->refresh();
        $this->assertTrue(Hash::check('NovaSenha2026y', $u->password));
        $this->assertTrue($u->must_change_password);
        $this->assertNull(User::query()->where('username', 'outro')->first());

        // Gerente nao troca senha de ninguem por aqui.
        $this->actingAs($this->gerente, 'web')->put(route('panel.professionals.update', $p->fresh()), [
            'display_name' => 'Sem Conta', 'version' => $p->fresh()->lock_version, 'user_id' => $u->id, 'access_reset_password' => 'Gerente2026z',
        ])->assertForbidden();
        $this->assertFalse(Hash::check('Gerente2026z', $u->fresh()->password));
    }
}

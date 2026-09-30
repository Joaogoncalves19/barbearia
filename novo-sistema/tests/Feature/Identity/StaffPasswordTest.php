<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\StaffResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Recuperacao, redefinicao e troca de senha da equipe; senha provisoria.
 */
class StaffPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRA = 'Se houver uma conta ativa com e-mail cadastrado';

    private function membro(array $attrs = []): User
    {
        return User::factory()->create(['username' => 'joana', 'email' => 'joana@barbearia.test'] + $attrs);
    }

    private function tokenDe(User $user): ?string
    {
        $token = null;
        Notification::assertSentTo($user, StaffResetPassword::class, function (StaffResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    // --- Pedido do link ----------------------------------------------------------------

    public function test_pedido_por_usuario_envia_link_para_o_e_mail_cadastrado(): void
    {
        Notification::fake();
        $user = $this->membro();

        $this->from(route('staff.password.request'))->post(route('staff.password.email'), ['identifier' => 'joana'])
            ->assertRedirect(route('staff.password.request'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::NEUTRA));

        Notification::assertSentTo($user, StaffResetPassword::class);
    }

    public function test_pedido_por_e_mail_tambem_funciona(): void
    {
        Notification::fake();
        $user = $this->membro();

        $this->post(route('staff.password.email'), ['identifier' => 'JOANA@barbearia.test']);

        Notification::assertSentTo($user, StaffResetPassword::class);
    }

    public function test_conta_inexistente_inativa_ou_sem_e_mail_recebe_a_mesma_resposta_e_nada_e_enviado(): void
    {
        Notification::fake();
        $this->membro(['is_active' => false]);
        User::factory()->create(['username' => 'semmail', 'email' => null]);

        foreach (['ninguem', 'ninguem@barbearia.test', 'joana', 'semmail'] as $identificador) {
            $this->post(route('staff.password.email'), ['identifier' => $identificador])
                ->assertSessionHas('status', fn ($s) => str_contains($s, self::NEUTRA));
        }

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_pedidos_repetidos_nao_geram_varios_e_mails(): void
    {
        Notification::fake();
        $user = $this->membro();

        $this->post(route('staff.password.email'), ['identifier' => 'joana']);
        $this->post(route('staff.password.email'), ['identifier' => 'joana'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::NEUTRA));

        Notification::assertSentToTimes($user, StaffResetPassword::class, 1);
    }

    public function test_pedidos_em_excesso_do_mesmo_ip_sao_bloqueados(): void
    {
        Notification::fake();
        $limite = config('barbearia.security.email_requests_per_ip');

        for ($i = 0; $i < $limite; $i++) {
            $this->post(route('staff.password.email'), ['identifier' => "pessoa{$i}"])->assertRedirect();
        }

        $this->post(route('staff.password.email'), ['identifier' => 'outra'])->assertStatus(429);
    }

    public function test_token_fica_guardado_so_como_hash(): void
    {
        Notification::fake();
        $user = $this->membro();
        $this->post(route('staff.password.email'), ['identifier' => 'joana']);

        $token = $this->tokenDe($user);
        $guardado = DB::table('password_reset_tokens')->where('email', 'joana@barbearia.test')->value('token');

        $this->assertNotSame($token, $guardado);
        $this->assertTrue(Hash::check($token, $guardado));
    }

    // --- Redefinicao -------------------------------------------------------------------

    public function test_redefine_com_token_valido_e_o_token_so_vale_uma_vez(): void
    {
        Notification::fake();
        $user = $this->membro();
        $this->post(route('staff.password.email'), ['identifier' => 'joana']);
        $token = $this->tokenDe($user);

        $this->get(route('staff.password.reset', $token))->assertOk()->assertSee('Criar nova senha');

        $dados = ['token' => $token, 'email' => 'joana@barbearia.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'];
        $this->post(route('staff.password.update'), $dados)->assertRedirect(route('staff.login'));

        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha123', $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertGuest('web'); // redefinir nao faz login

        // Segundo uso do mesmo link: recusado.
        $this->post(route('staff.password.update'), ['password' => 'OutraSenha456', 'password_confirmation' => 'OutraSenha456'] + $dados)
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NovaSenha123', $user->fresh()->password));
    }

    public function test_token_vencido_e_recusado(): void
    {
        $user = $this->membro();
        $token = Password::broker('users')->createToken($user);
        $this->travel(61)->minutes();

        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'joana@barbearia.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors(['email' => 'Este link de redefinição não é válido ou já expirou. Peça um novo.']);
    }

    public function test_token_de_uma_pessoa_nao_redefine_a_senha_de_outra(): void
    {
        $joana = $this->membro();
        $outro = User::factory()->create(['email' => 'outro@barbearia.test']);
        $token = Password::broker('users')->createToken($joana);

        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'outro@barbearia.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('NovaSenha123', $outro->fresh()->password));
    }

    public function test_conta_desativada_depois_do_pedido_nao_redefine(): void
    {
        $user = $this->membro();
        $token = Password::broker('users')->createToken($user);
        $user->forceFill(['is_active' => false])->save();

        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'joana@barbearia.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors('email');
    }

    public function test_senha_nova_precisa_seguir_a_politica(): void
    {
        $user = $this->membro();
        $token = Password::broker('users')->createToken($user);
        $base = ['token' => $token, 'email' => 'joana@barbearia.test'];

        $this->post(route('staff.password.update'), $base + ['password' => 'curta1', 'password_confirmation' => 'curta1'])->assertSessionHasErrors('password');
        $this->post(route('staff.password.update'), $base + ['password' => 'sonumeros', 'password_confirmation' => 'sonumeros'])->assertSessionHasErrors('password');
        $longa = str_repeat('ã', 40).'1a'; // 82 bytes: o bcrypt truncaria
        $this->post(route('staff.password.update'), $base + ['password' => $longa, 'password_confirmation' => $longa])->assertSessionHasErrors('password');
        $this->post(route('staff.password.update'), $base + ['password' => 'NovaSenha123', 'password_confirmation' => 'Diferente123'])->assertSessionHasErrors('password');
    }

    public function test_redefinicao_invalida_o_manter_conectado_antigo(): void
    {
        $user = $this->membro();
        $user->forceFill(['remember_token' => 'token-antigo-de-lembrar'])->save();
        $token = Password::broker('users')->createToken($user);

        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'joana@barbearia.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123']);

        $this->assertNotSame('token-antigo-de-lembrar', $user->fresh()->getRememberToken());
    }

    // --- Troca logado -------------------------------------------------------------------

    public function test_troca_exige_a_senha_atual_e_uma_senha_diferente(): void
    {
        $user = $this->membro();

        $this->actingAs($user, 'web')->put(route('panel.password.update'), ['current_password' => 'errada', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user, 'web')->put(route('panel.password.update'), ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors('password');

        $this->actingAs($user, 'web')->put(route('panel.password.update'), ['current_password' => 'password', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertRedirect(route('panel.home'));
        $this->assertTrue(Hash::check('NovaSenha123', $user->fresh()->password));
    }

    public function test_trocar_a_senha_derruba_as_outras_sessoes(): void
    {
        $user = $this->membro();

        // Sessao "do outro aparelho": ja guardou o hash da senha antiga.
        $this->actingAs($user, 'web')->get(route('panel.home'))->assertOk();
        $sessaoAntiga = session()->all();

        $user->forceFill(['password' => 'NovaSenha123'])->save();

        $this->withSession($sessaoAntiga)->actingAs($user->fresh(), 'web')->get(route('panel.home'))
            ->assertRedirect(route('staff.login'));
    }

    // --- Senha provisoria ----------------------------------------------------------------

    public function test_senha_provisoria_obriga_a_troca_antes_de_qualquer_tela(): void
    {
        $user = $this->membro(['must_change_password' => true]);

        $this->actingAs($user, 'web')->get(route('panel.home'))->assertRedirect(route('panel.password.edit'));
        $this->actingAs($user, 'web')->get(route('panel.account.edit'))->assertRedirect(route('panel.password.edit'));
        $this->actingAs($user, 'web')->get(route('panel.password.edit'))->assertOk()->assertSee('Crie sua senha pessoal');

        $this->actingAs($user, 'web')->put(route('panel.password.update'), ['current_password' => 'password', 'password' => 'MinhaSenha123', 'password_confirmation' => 'MinhaSenha123']);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->actingAs($user->fresh(), 'web')->get(route('panel.home'))->assertOk();
    }
}

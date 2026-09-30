<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Login da EQUIPE: usuario OU e-mail + senha (decisao da Fase 3).
 */
class StaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ERRO = 'Usuário, e-mail ou senha incorretos.';

    private function membro(array $attrs = []): User
    {
        return User::factory()->create(['username' => 'joana', 'email' => 'joana@barbearia.test'] + $attrs);
    }

    public function test_tela_de_login_da_equipe_abre(): void
    {
        $this->get(route('staff.login'))->assertOk()->assertSee('Acesso da equipe')->assertSee('Usuário ou e-mail');
    }

    public function test_entra_com_nome_de_usuario(): void
    {
        $user = $this->membro();

        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password'])
            ->assertRedirect(route('panel.home'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_entra_com_e_mail_sem_diferenciar_maiusculas(): void
    {
        $user = $this->membro();

        $this->post(route('staff.login.attempt'), ['identifier' => '  Joana@Barbearia.TEST ', 'password' => 'password'])
            ->assertRedirect(route('panel.home'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_usuario_tambem_ignora_maiusculas(): void
    {
        $this->membro();

        $this->post(route('staff.login.attempt'), ['identifier' => 'JOANA', 'password' => 'password'])
            ->assertRedirect(route('panel.home'));
    }

    public function test_senha_errada_e_conta_inexistente_recebem_a_mesma_resposta(): void
    {
        $this->membro();

        $existe = $this->from(route('staff.login'))->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'errada123']);
        $naoExiste = $this->from(route('staff.login'))->post(route('staff.login.attempt'), ['identifier' => 'ninguem', 'password' => 'errada123']);
        $emailNaoExiste = $this->from(route('staff.login'))->post(route('staff.login.attempt'), ['identifier' => 'ninguem@barbearia.test', 'password' => 'errada123']);

        $this->assertGuest('web');
        foreach ([$existe, $naoExiste, $emailNaoExiste] as $resposta) {
            $resposta->assertRedirect(route('staff.login'))->assertSessionHasErrors(['identifier' => self::ERRO]);
        }
    }

    public function test_conta_inativa_nao_entra_e_nao_se_distingue_de_senha_errada(): void
    {
        $this->membro(['is_active' => false]);

        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password'])
            ->assertSessionHasErrors(['identifier' => self::ERRO]);
        $this->assertGuest('web');
    }

    public function test_conta_sem_senha_nunca_entra(): void
    {
        // Conta importada com hash nao reconhecido: password nulo.
        $user = $this->membro();
        $user->forceFill(['password' => null])->save();

        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => ''])->assertSessionHasErrors('password');
        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'qualquer1'])
            ->assertSessionHasErrors(['identifier' => self::ERRO]);
        $this->assertGuest('web');
    }

    public function test_senha_e_guardada_com_hash_e_nunca_em_texto(): void
    {
        $user = User::factory()->create(['password' => 'SenhaForte123']);
        $bruto = $user->getRawOriginal('password');

        $this->assertNotSame('SenhaForte123', $bruto);
        $this->assertTrue(Hash::isHashed($bruto));
        $this->assertTrue(Hash::check('SenhaForte123', $bruto));
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_login_regenera_a_sessao_contra_fixacao(): void
    {
        $this->membro();
        $this->get(route('staff.login'));
        $antes = session()->getId();

        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password']);

        $this->assertNotSame($antes, session()->getId());
    }

    public function test_tentativas_em_excesso_sao_bloqueadas_com_429(): void
    {
        RateLimiter::clear('login');
        $this->membro();
        $max = config('barbearia.security.login_max_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'errada'.$i]);
        }

        // Nem a senha certa passa enquanto o limite vale.
        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_limite_vale_para_o_mesmo_login_escrito_de_outro_jeito(): void
    {
        $this->membro();
        $max = config('barbearia.security.login_max_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->post(route('staff.login.attempt'), ['identifier' => $i % 2 ? 'JOANA' : ' joana ', 'password' => 'errada'.$i]);
        }

        $this->post(route('staff.login.attempt'), ['identifier' => 'Joana', 'password' => 'password'])->assertStatus(429);
    }

    public function test_logout_so_por_post_e_encerra_a_sessao(): void
    {
        $user = $this->membro();

        $this->actingAs($user, 'web')->get('/painel/sair')->assertStatus(405);
        $this->actingAs($user, 'web')->post(route('staff.logout'))->assertRedirect(route('staff.login'));
        $this->assertGuest('web');
    }

    public function test_logado_nao_ve_a_tela_de_login(): void
    {
        $this->actingAs($this->membro(), 'web')->get(route('staff.login'))->assertRedirect(route('panel.home'));
    }

    public function test_manter_conectado_dura_no_maximo_14_dias(): void
    {
        $this->membro();

        $resposta = $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password', 'remember' => '1']);

        $cookie = collect($resposta->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie, 'cookie de lembrar');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertLessThanOrEqual(now()->addDays(14)->addMinute()->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_login_e_logout_vao_para_a_auditoria_sem_senha(): void
    {
        $user = $this->membro();

        $this->post(route('staff.login.attempt'), ['identifier' => 'joana', 'password' => 'password']);
        $this->post(route('staff.logout'));

        $acoes = AuditLog::query()->where('auditable_type', 'User')->where('auditable_id', $user->id)->pluck('action')->all();
        $this->assertContains('auth.login', $acoes);
        $this->assertContains('auth.logout', $acoes);
        $trilha = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString('"password"', $trilha);
        $this->assertStringNotContainsString('$2y$', $trilha, 'nenhum hash de senha na auditoria');
    }

    public function test_campo_de_senha_nunca_e_repreenchido(): void
    {
        $this->from(route('staff.login'))->post(route('staff.login.attempt'), ['identifier' => 'x', 'password' => 'segredo-digitado']);

        $this->get(route('staff.login'))->assertDontSee('segredo-digitado');
    }
}

<?php

namespace Tests\Feature\Security;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_login_abre(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Acesso da equipe');
    }

    public function test_login_valido_entra_no_painel_e_registra_ultimo_acesso(): void
    {
        $user = User::factory()->create(['email' => 'recepcao@barbearia.test']);

        $this->post(route('login.attempt'), ['email' => 'Recepcao@Barbearia.test', 'password' => 'password'])
            ->assertRedirect(route('panel.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_senha_errada_nao_entra_e_nao_revela_se_a_conta_existe(): void
    {
        User::factory()->create(['email' => 'existe@barbearia.test']);

        $existe = $this->from(route('login'))->post(route('login.attempt'), ['email' => 'existe@barbearia.test', 'password' => 'errada123']);
        $naoExiste = $this->from(route('login'))->post(route('login.attempt'), ['email' => 'naoexiste@barbearia.test', 'password' => 'errada123']);

        $this->assertGuest();
        $existe->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
        $naoExiste->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
    }

    public function test_usuario_inativo_nao_entra_mesmo_com_senha_certa(): void
    {
        User::factory()->inactive()->create(['email' => 'inativo@barbearia.test']);

        $this->post(route('login.attempt'), ['email' => 'inativo@barbearia.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_regenera_a_sessao_contra_fixacao(): void
    {
        User::factory()->create(['email' => 'a@barbearia.test']);
        $this->get(route('login'));
        $antes = session()->getId();

        $this->post(route('login.attempt'), ['email' => 'a@barbearia.test', 'password' => 'password']);

        $this->assertNotSame($antes, session()->getId());
    }

    public function test_tentativas_em_excesso_sao_bloqueadas(): void
    {
        RateLimiter::clear('login');
        User::factory()->create(['email' => 'alvo@barbearia.test']);
        $max = config('barbearia.security.login_max_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->post(route('login.attempt'), ['email' => 'alvo@barbearia.test', 'password' => 'errada'.$i]);
        }

        $this->post(route('login.attempt'), ['email' => 'alvo@barbearia.test', 'password' => 'password'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_exige_post_e_encerra_a_sessao(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sair')->assertStatus(405);
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_usuario_logado_nao_ve_a_tela_de_login(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('panel.home'));
    }
}

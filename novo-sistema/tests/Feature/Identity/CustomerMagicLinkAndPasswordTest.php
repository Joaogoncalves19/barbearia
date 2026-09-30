<?php

namespace Tests\Feature\Identity;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\CustomerLoginToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\CustomerMagicLink;
use App\Modules\Identity\Notifications\CustomerResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Link magico (entrar sem senha) e recuperacao de senha do cliente.
 */
class CustomerMagicLinkAndPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function pedirLink(Customer $c): string
    {
        Notification::fake();
        $this->post(route('customer.magic.send'), ['email' => $c->email]);

        $token = null;
        Notification::assertSentTo($c, CustomerMagicLink::class, function (CustomerMagicLink $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return (string) $token;
    }

    // --- Link magico ----------------------------------------------------------------------

    public function test_link_magico_entra_so_depois_do_clique_em_entrar(): void
    {
        $c = Customer::factory()->create();
        $token = $this->pedirLink($c);

        // Abrir o link (GET) nao faz login nem gasta o token.
        $this->get(route('customer.magic.show', $token))->assertOk()->assertSee('Entrar na minha conta');
        $this->assertGuest('customer');
        $this->assertNull(CustomerLoginToken::query()->first()->used_at);

        $this->post(route('customer.magic.consume', $token))->assertRedirect(route('account.home'));
        $this->assertAuthenticatedAs($c, 'customer');
    }

    public function test_link_magico_so_vale_uma_vez(): void
    {
        $c = Customer::factory()->create();
        $token = $this->pedirLink($c);

        $this->post(route('customer.magic.consume', $token));
        $this->post(route('account.logout'));

        $this->post(route('customer.magic.consume', $token))->assertRedirect(route('customer.magic.request'));
        $this->assertGuest('customer');
        $this->get(route('customer.magic.show', $token))->assertSee('Link inválido');
    }

    public function test_link_magico_vence(): void
    {
        $c = Customer::factory()->create();
        $token = $this->pedirLink($c);

        $this->travel(config('barbearia.security.magic_link_minutes') + 1)->minutes();

        $this->post(route('customer.magic.consume', $token))->assertRedirect(route('customer.magic.request'));
        $this->assertGuest('customer');
    }

    public function test_pedir_novo_link_invalida_o_anterior(): void
    {
        $c = Customer::factory()->create();
        $primeiro = $this->pedirLink($c);
        $this->travel(2)->minutes();
        $segundo = $this->pedirLink($c);

        $this->post(route('customer.magic.consume', $primeiro))->assertRedirect(route('customer.magic.request'));
        $this->post(route('customer.magic.consume', $segundo))->assertRedirect(route('account.home'));
    }

    public function test_token_do_link_fica_guardado_so_como_hash(): void
    {
        $c = Customer::factory()->create();
        $token = $this->pedirLink($c);

        $registro = CustomerLoginToken::query()->sole();
        $this->assertNotSame($token, $registro->token_hash);
        $this->assertSame(hash('sha256', $token), $registro->token_hash);
        $this->assertSame(0, CustomerLoginToken::query()->where('token_hash', $token)->count());
    }

    public function test_e_mail_sem_conta_ou_conta_inativa_tem_a_mesma_resposta_e_nada_e_enviado(): void
    {
        Notification::fake();
        $inativo = Customer::factory()->create(['email' => 'inativo@exemplo.test']);
        $inativo->forceFill(['status' => CustomerStatus::Inactive])->save();

        foreach (['ninguem@exemplo.test', 'inativo@exemplo.test'] as $email) {
            $this->post(route('customer.magic.send'), ['email' => $email])
                ->assertSessionHas('status', fn ($s) => str_contains($s, 'Se este e-mail tiver cadastro'));
        }

        Notification::assertNothingSent();
        $this->assertSame(0, CustomerLoginToken::query()->count());
    }

    public function test_conta_desativada_depois_do_pedido_nao_entra_pelo_link(): void
    {
        $c = Customer::factory()->create();
        $token = $this->pedirLink($c);
        $c->forceFill(['status' => CustomerStatus::Inactive])->save();

        $this->post(route('customer.magic.consume', $token))->assertRedirect(route('customer.magic.request'));
        $this->assertGuest('customer');
    }

    public function test_link_magico_confirma_o_e_mail(): void
    {
        $c = Customer::factory()->unverified()->create();
        $token = $this->pedirLink($c);

        $this->post(route('customer.magic.consume', $token));

        $this->assertNotNull($c->fresh()->email_verified_at);
    }

    public function test_token_inventado_nao_entra(): void
    {
        Customer::factory()->create();

        $this->post(route('customer.magic.consume', str_repeat('a', 64)))->assertRedirect(route('customer.magic.request'));
        $this->assertGuest('customer');
    }

    public function test_tentativas_de_token_em_excesso_sao_bloqueadas(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('customer.magic.consume', 'chute'.$i));
        }

        $this->post(route('customer.magic.consume', 'mais-um'))->assertStatus(429);
    }

    // --- Recuperacao de senha ---------------------------------------------------------------

    public function test_recuperacao_de_senha_do_cliente_ponta_a_ponta(): void
    {
        Notification::fake();
        $c = Customer::factory()->create(['email' => 'ana@exemplo.test']);

        $this->post(route('customer.password.email'), ['email' => 'ana@exemplo.test'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Se este e-mail tiver cadastro'));

        $token = null;
        Notification::assertSentTo($c, CustomerResetPassword::class, function (CustomerResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('customer.password.update'), ['token' => $token, 'email' => 'ana@exemplo.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertRedirect(route('customer.login'));

        $this->assertTrue(Hash::check('NovaSenha123', $c->fresh()->password));
        $this->post(route('customer.password.update'), ['token' => $token, 'email' => 'ana@exemplo.test', 'password' => 'Outra123abc', 'password_confirmation' => 'Outra123abc'])
            ->assertSessionHasErrors('email');
    }

    public function test_cliente_sem_senha_cria_uma_pela_redefinicao(): void
    {
        $c = Customer::factory()->withoutPassword()->unverified()->create(['email' => 'balcao@exemplo.test']);
        $token = Password::broker('customers')->createToken($c);

        $this->post(route('customer.password.update'), ['token' => $token, 'email' => 'balcao@exemplo.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123']);

        $c->refresh();
        $this->assertTrue(Hash::check('NovaSenha123', $c->password));
        $this->assertNotNull($c->email_verified_at, 'quem recebeu o link provou o e-mail');
    }

    public function test_e_mail_desconhecido_nao_envia_nada(): void
    {
        Notification::fake();

        $this->post(route('customer.password.email'), ['email' => 'ninguem@exemplo.test'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Se este e-mail tiver cadastro'));

        Notification::assertNothingSent();
    }

    public function test_cliente_e_equipe_com_o_mesmo_e_mail_tem_tokens_separados(): void
    {
        $cliente = Customer::factory()->create(['email' => 'mesmo@exemplo.test']);
        $membro = User::factory()->create(['email' => 'mesmo@exemplo.test']);

        $tokenCliente = Password::broker('customers')->createToken($cliente);
        $tokenEquipe = Password::broker('users')->createToken($membro);

        $this->assertSame(1, DB::table('customer_password_reset_tokens')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->count());

        // O token da equipe nao redefine a senha do cliente (e vice-versa).
        $this->post(route('customer.password.update'), ['token' => $tokenEquipe, 'email' => 'mesmo@exemplo.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors('email');
        $this->post(route('staff.password.update'), ['token' => $tokenCliente, 'email' => 'mesmo@exemplo.test', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrors('email');
    }
}

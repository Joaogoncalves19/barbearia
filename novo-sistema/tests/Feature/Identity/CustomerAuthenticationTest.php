<?php

namespace Tests\Feature\Identity;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Notifications\CustomerAccountAlreadyExists;
use App\Modules\Identity\Notifications\CustomerRegistrationNotCompleted;
use App\Modules\Identity\Notifications\CustomerVerifyEmail;
use Database\Factories\CustomerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cadastro (CPF obrigatorio), confirmacao de e-mail e login do CLIENTE.
 */
class CustomerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PRONTO = 'Pronto! Enviamos um e-mail para';

    /**
     * @return array<string, mixed>
     */
    private function cadastro(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'name' => 'Cliente Ficticio',
            'email' => 'novo@exemplo.test',
            'cpf' => CustomerFactory::fakeCpf(),
            'phone' => '(11) 98888-7777',
            'password' => 'SenhaBoa123',
            'password_confirmation' => 'SenhaBoa123',
        ];
    }

    // --- Cadastro ------------------------------------------------------------------------

    public function test_cadastro_cria_conta_nao_confirmada_sem_login_automatico(): void
    {
        Notification::fake();
        $cpf = CustomerFactory::fakeCpf();

        $this->post(route('customer.register.store'), $this->cadastro(['cpf' => substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9)]))
            ->assertRedirect(route('customer.login'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::PRONTO));

        $c = Customer::query()->where('email', 'novo@exemplo.test')->firstOrFail();
        $this->assertSame($cpf, $c->cpf, 'CPF normalizado so com digitos');
        $this->assertSame('+5511988887777', $c->phone);
        $this->assertNull($c->email_verified_at);
        $this->assertTrue(Hash::check('SenhaBoa123', $c->password));
        $this->assertSame(MarketingConsent::Unknown, $c->marketing_email_consent, 'sem marcar = consentimento desconhecido');
        $this->assertGuest('customer');
        Notification::assertSentTo($c, CustomerVerifyEmail::class);
    }

    public function test_cpf_e_obrigatorio_e_precisa_ser_valido(): void
    {
        $this->post(route('customer.register.store'), $this->cadastro(['cpf' => '']))->assertSessionHasErrors('cpf');
        $this->post(route('customer.register.store'), $this->cadastro(['cpf' => '123.456.789-00']))->assertSessionHasErrors(['cpf' => 'Informe um CPF válido.']);
        $this->post(route('customer.register.store'), $this->cadastro(['cpf' => '111.111.111-11']))->assertSessionHasErrors('cpf');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_marcar_novidades_registra_o_consentimento_com_prova(): void
    {
        Notification::fake();
        $this->post(route('customer.register.store'), $this->cadastro(['marketing' => '1']));

        $c = Customer::query()->where('email', 'novo@exemplo.test')->firstOrFail();
        $this->assertSame(MarketingConsent::Granted, $c->marketing_email_consent);
        $this->assertSame(1, $c->consentRecords()->count());
    }

    public function test_e_mail_ja_cadastrado_tem_a_mesma_resposta_e_o_dono_recebe_o_aviso(): void
    {
        Notification::fake();
        $existente = Customer::factory()->create(['email' => 'novo@exemplo.test']);

        $this->post(route('customer.register.store'), $this->cadastro(['email' => 'NOVO@exemplo.test']))
            ->assertRedirect(route('customer.login'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::PRONTO));

        $this->assertSame(1, Customer::query()->count(), 'nada foi criado');
        Notification::assertSentTo($existente, CustomerAccountAlreadyExists::class);
        Notification::assertNotSentTo($existente, CustomerVerifyEmail::class);
    }

    public function test_cpf_de_outro_cliente_nao_cria_nem_mescla_e_a_tela_nao_revela(): void
    {
        Notification::fake();
        $outro = Customer::factory()->create();

        $this->post(route('customer.register.store'), $this->cadastro(['cpf' => $outro->cpf]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::PRONTO));

        $this->assertSame(1, Customer::query()->count());
        Notification::assertSentOnDemand(CustomerRegistrationNotCompleted::class,
            fn ($n, $canais, AnonymousNotifiable $para) => $para->routes['mail'] === 'novo@exemplo.test');
        Notification::assertNotSentTo($outro, CustomerAccountAlreadyExists::class);
    }

    public function test_cadastros_em_excesso_do_mesmo_ip_sao_bloqueados(): void
    {
        Notification::fake();
        $limite = config('barbearia.security.registrations_per_ip');

        for ($i = 0; $i < $limite; $i++) {
            $this->post(route('customer.register.store'), $this->cadastro(['email' => "p{$i}@exemplo.test", 'cpf' => CustomerFactory::fakeCpf(), 'phone' => null]));
        }

        $this->post(route('customer.register.store'), $this->cadastro(['email' => 'ultimo@exemplo.test']))->assertStatus(429);
    }

    // --- Confirmacao de e-mail -------------------------------------------------------------

    public function test_link_de_confirmacao_confirma_o_e_mail(): void
    {
        $c = Customer::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('customer.verification.verify', now()->addHour(), ['customer' => $c->public_id, 'hash' => sha1($c->email)]);

        $this->get($url)->assertRedirect(route('customer.login'));

        $this->assertNotNull($c->fresh()->email_verified_at);
    }

    public function test_link_de_confirmacao_adulterado_vencido_ou_de_outro_e_mail_e_recusado(): void
    {
        $c = Customer::factory()->unverified()->create();
        $valido = URL::temporarySignedRoute('customer.verification.verify', now()->addHour(), ['customer' => $c->public_id, 'hash' => sha1($c->email)]);

        $this->get($valido.'x')->assertForbidden(); // assinatura quebrada

        $outroEmail = URL::temporarySignedRoute('customer.verification.verify', now()->addHour(), ['customer' => $c->public_id, 'hash' => sha1('outro@exemplo.test')]);
        $this->get($outroEmail)->assertNotFound();

        $vencido = URL::temporarySignedRoute('customer.verification.verify', now()->subMinute(), ['customer' => $c->public_id, 'hash' => sha1($c->email)]);
        $this->get($vencido)->assertForbidden();

        $this->assertNull($c->fresh()->email_verified_at);
    }

    public function test_e_mail_de_confirmacao_usa_o_codigo_publico_e_nao_o_id(): void
    {
        $c = Customer::factory()->unverified()->create();
        $mail = (new CustomerVerifyEmail)->toMail($c);

        $this->assertStringContainsString($c->public_id, $mail->actionUrl);
        $this->assertStringStartsWith('https://barbearia.test/', $mail->actionUrl, 'link sempre pelo APP_URL');
    }

    // --- Login ---------------------------------------------------------------------------

    public function test_login_com_e_mail_e_senha(): void
    {
        $c = Customer::factory()->create(['email' => 'ana@exemplo.test']);

        $this->post(route('customer.login.attempt'), ['email' => 'Ana@Exemplo.test', 'password' => 'senha-de-teste-123'])
            ->assertRedirect(route('account.home'));

        $this->assertAuthenticatedAs($c, 'customer');
        $this->assertGuest('web');
    }

    public function test_senha_errada_e_e_mail_inexistente_tem_a_mesma_resposta(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.test']);

        foreach ([['ana@exemplo.test', 'errada123'], ['ninguem@exemplo.test', 'errada123']] as [$email, $senha]) {
            $this->post(route('customer.login.attempt'), ['email' => $email, 'password' => $senha])
                ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
        }
        $this->assertGuest('customer');
    }

    public function test_cliente_inativo_mesclado_anonimizado_ou_excluido_nao_entra(): void
    {
        $inativo = Customer::factory()->create(['email' => 'inativo@exemplo.test']);
        $inativo->forceFill(['status' => CustomerStatus::Inactive])->save();

        $principal = Customer::factory()->create();
        $mesclado = Customer::factory()->create(['email' => 'mesclado@exemplo.test']);
        $mesclado->forceFill(['merged_into_customer_id' => $principal->id])->save();

        $anonimo = Customer::factory()->create(['email' => 'anonimo@exemplo.test']);
        $anonimo->forceFill(['anonymized_at' => now()])->save();

        $excluido = Customer::factory()->create(['email' => 'excluido@exemplo.test']);
        $excluido->delete();

        foreach (['inativo', 'mesclado', 'anonimo', 'excluido'] as $quem) {
            $this->post(route('customer.login.attempt'), ['email' => "{$quem}@exemplo.test", 'password' => 'senha-de-teste-123'])
                ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
        }
        $this->assertGuest('customer');
    }

    public function test_e_mail_nao_confirmado_nao_entra_e_recebe_novo_link(): void
    {
        Notification::fake();
        $c = Customer::factory()->unverified()->create(['email' => 'ana@exemplo.test']);

        $this->post(route('customer.login.attempt'), ['email' => 'ana@exemplo.test', 'password' => 'senha-de-teste-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('customer');
        Notification::assertSentTo($c, CustomerVerifyEmail::class);
    }

    public function test_senha_antiga_bcrypt_do_sistema_legado_entra_e_e_re_hasheada(): void
    {
        $c = Customer::factory()->create(['email' => 'legado@exemplo.test']);
        // Como o importador grava: hash antigo copiado como esta (bcrypt custo 10).
        $hashAntigo = password_hash('SenhaDoLegado1', PASSWORD_BCRYPT, ['cost' => 10]);
        DB::table('customers')->where('id', $c->id)->update(['password' => $hashAntigo]);

        $this->post(route('customer.login.attempt'), ['email' => 'legado@exemplo.test', 'password' => 'SenhaDoLegado1'])
            ->assertRedirect(route('account.home'));

        $novo = $c->fresh()->getAuthPassword();
        $this->assertNotSame($hashAntigo, $novo, 'reforco do hash no primeiro login');
        $this->assertFalse(Hash::needsRehash($novo));
        $this->assertTrue(Hash::check('SenhaDoLegado1', $novo));
    }

    public function test_cliente_sem_senha_nunca_entra_por_senha(): void
    {
        Customer::factory()->withoutPassword()->create(['email' => 'semsenha@exemplo.test']);

        $this->post(route('customer.login.attempt'), ['email' => 'semsenha@exemplo.test', 'password' => 'qualquer123'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
    }

    public function test_logout_do_cliente(): void
    {
        $c = Customer::factory()->create();

        $this->actingAs($c, 'customer')->post(route('account.logout'))->assertRedirect(route('customer.login'));
        $this->assertGuest('customer');
    }

    public function test_manter_conectado_do_cliente_dura_no_maximo_30_dias(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.test']);

        $resposta = $this->post(route('customer.login.attempt'), ['email' => 'ana@exemplo.test', 'password' => 'senha-de-teste-123', 'remember' => '1']);

        $cookie = collect($resposta->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_customer_'));
        $this->assertNotNull($cookie);
        $this->assertLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp(), $cookie->getExpiresTime());
    }
}

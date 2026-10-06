<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\EnsureCustomerRecentlyConfirmed;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Notifications\CustomerEmailChanged;
use App\Modules\Identity\Notifications\CustomerEmailChangeLink;
use App\Modules\Identity\Services\CustomerEmailChange;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Troca de e-mail confirmada pelo link no endereco novo, e "baixar meus
 * dados" (LGPD). As duas pedem a senha de novo; nenhuma revela se outro
 * cadastro usa um endereco.
 */
class EmailChangeAndExportTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Notification::fake();
        $this->cliente->forceFill(['email' => 'cliente.antiga@exemplo.test', 'password' => Hash::make('senha-ficticia-123')])->save();
    }

    private function confirmed(): static
    {
        return $this->withSession([EnsureCustomerRecentlyConfirmed::SESSION_KEY => time()]);
    }

    /** Pede a troca e captura o token do e-mail enviado ao endereco novo. */
    private function requestChange(string $novo = 'cliente.nova@exemplo.test'): void
    {
        $this->actingAs($this->cliente, 'customer')->confirmed()->put(route('account.email.update'), ['email' => $novo])
            ->assertRedirect(route('account.profile.edit'));
        Notification::assertSentOnDemand(CustomerEmailChangeLink::class, function (CustomerEmailChangeLink $n, array $canais, AnonymousNotifiable $quem) use ($novo) {
            $this->token = $n->token;

            return $quem->routes['mail'] === $novo;
        });
    }

    public function test_troca_so_vale_depois_de_confirmar_pelo_link_e_avisa_o_endereco_antigo(): void
    {
        $this->requestChange();
        $this->assertSame('cliente.antiga@exemplo.test', $this->cliente->fresh()->email, 'pedir nao troca');
        $this->assertSame(hash('sha256', $this->token), DB::table('customers')->where('id', $this->cliente->id)->value('pending_email_token'), 'banco guarda so o hash');

        // Abrir o link so mostra; quem troca e o POST.
        $this->actingAs($this->cliente, 'customer')->get(route('account.email.confirm.show', ['token' => $this->token]))->assertOk()->assertSee('cliente.nova@exemplo.test');
        $this->assertSame('cliente.antiga@exemplo.test', $this->cliente->fresh()->email);

        $this->actingAs($this->cliente, 'customer')->post(route('account.email.confirm', ['token' => $this->token]))->assertRedirect(route('account.profile.edit'));
        $c = $this->cliente->fresh();
        $this->assertSame('cliente.nova@exemplo.test', $c->email);
        $this->assertNotNull($c->email_verified_at);
        $this->assertNull($c->pending_email);
        Notification::assertSentOnDemand(CustomerEmailChanged::class, fn ($n, $canais, AnonymousNotifiable $quem) => $quem->routes['mail'] === 'cliente.antiga@exemplo.test');
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.email_changed')->count());

        // O link vale uma vez.
        $this->actingAs($this->cliente, 'customer')->get(route('account.email.confirm.show', ['token' => $this->token]))->assertNotFound();
    }

    public function test_endereco_de_outro_cadastro_tem_a_mesma_resposta_e_nada_e_enviado(): void
    {
        Customer::factory()->create(['email' => 'ja.existe@exemplo.test']);

        $this->actingAs($this->cliente, 'customer')->confirmed()->put(route('account.email.update'), ['email' => 'ja.existe@exemplo.test'])
            ->assertRedirect(route('account.profile.edit'))
            ->assertSessionHas('status', 'Se o endereço puder ser usado, enviamos para ele um link de confirmação. O e-mail da conta só muda depois que você confirmar pelo link.');
        Notification::assertNothingSent();
        $this->assertNull($this->cliente->fresh()->pending_email);
    }

    public function test_link_de_outra_conta_ou_vencido_nao_vale(): void
    {
        $this->requestChange();
        $outra = Customer::factory()->create();
        $this->flushSession(); // outra pessoa entrando

        $this->actingAs($outra, 'customer')->get(route('account.email.confirm.show', ['token' => $this->token]))->assertNotFound();
        $this->actingAs($outra, 'customer')->post(route('account.email.confirm', ['token' => $this->token]))->assertRedirect(route('account.profile.edit'));
        $this->assertNotSame('cliente.nova@exemplo.test', $outra->fresh()->email);

        $this->travel(61)->minutes();
        $this->flushSession();
        $this->actingAs($this->cliente, 'customer')->post(route('account.email.confirm', ['token' => $this->token]))->assertSessionHasErrors('email');
        $this->assertSame('cliente.antiga@exemplo.test', $this->cliente->fresh()->email);
    }

    public function test_endereco_tomado_entre_o_pedido_e_a_confirmacao_nao_e_roubado(): void
    {
        $this->requestChange();
        Customer::factory()->create(['email' => 'cliente.nova@exemplo.test']);

        $this->assertFalse(app(CustomerEmailChange::class)->confirm($this->cliente->fresh(), $this->token));
        $this->assertSame('cliente.antiga@exemplo.test', $this->cliente->fresh()->email);
    }

    public function test_pedir_a_troca_exige_a_senha_de_novo_e_pode_ser_cancelado(): void
    {
        $this->actingAs($this->cliente, 'customer')->get(route('account.email.edit'))->assertRedirect(route('account.confirm.show'));
        $this->actingAs($this->cliente, 'customer')->put(route('account.email.update'), ['email' => 'x@exemplo.test'])->assertRedirect(route('account.confirm.show'));
        Notification::assertNothingSent();

        $this->requestChange();
        $this->actingAs($this->cliente->fresh(), 'customer')->get(route('account.profile.edit'))->assertSee('data-pending-email', false);
        $this->actingAs($this->cliente, 'customer')->delete(route('account.email.cancel'))->assertRedirect(route('account.profile.edit'));
        $this->actingAs($this->cliente, 'customer')->get(route('account.email.confirm.show', ['token' => $this->token]))->assertNotFound();
    }

    public function test_exportar_exige_senha_e_entrega_json_com_cpf_mascarado(): void
    {
        $this->actingAs($this->cliente, 'customer')->post(route('account.privacy.export'))->assertRedirect(route('account.confirm.show'));

        $r = $this->actingAs($this->cliente, 'customer')->confirmed()->post(route('account.privacy.export'));
        $r->assertOk();
        $this->assertStringContainsString('attachment; filename=meus-dados-2026-10-05.json', (string) $r->headers->get('Content-Disposition'));
        $dados = json_decode($r->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('cliente.antiga@exemplo.test', $dados['cadastro']['email']);
        $this->assertStringNotContainsString((string) $this->cliente->cpf, $r->streamedContent());
        $this->assertMatchesRegularExpression('/^\d{3}\.\*{3}\.\*{3}-\d{2}$/', $dados['cadastro']['cpf']);
        $this->assertArrayHasKey('consentimentos', $dados);
        $this->assertArrayNotHasKey('password', $dados['cadastro']);
        $this->assertStringNotContainsString('cost_cents', $r->streamedContent());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.data_exported')->count());
    }

    public function test_exportar_tem_limite_por_hora(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->cliente, 'customer')->confirmed()->post(route('account.privacy.export'))->assertOk();
        }
        $this->actingAs($this->cliente, 'customer')->confirmed()->post(route('account.privacy.export'))->assertStatus(429);
    }
}

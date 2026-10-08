<?php

namespace Tests\Feature\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerErasure;
use App\Modules\Customers\Services\CustomerLookup;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\CustomerVerifyEmail;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Cadastro de cliente pelo painel e ativar/desativar (Fase 13, decisoes do
 * dono). A equipe so INICIA o cadastro: CPF obrigatorio e unico, as mesmas
 * validacoes do site, sem senha, sem e-mail confirmado e sem consentimento
 * pela equipe; com e-mail, o mesmo link de confirmacao do cadastro pelo site.
 */
class PanelCustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    private User $gerente;

    private User $recepcao;

    private User $financeiro;

    private User $barbeiro;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->dono = User::factory()->role(StaffRole::Owner)->create();
        $this->gerente = User::factory()->manager()->create();
        $this->recepcao = User::factory()->role(StaffRole::Reception)->create();
        $this->financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->barbeiro = User::factory()->role(StaffRole::Professional)->create();
    }

    /** @return array<string, string> */
    private function dados(array $extra = []): array
    {
        return $extra + ['name' => 'Cliente do Balcão', 'cpf' => '529.982.247-25', 'phone' => '(11) 98888-7777', 'birth_date' => '1990-03-10', 'email' => ''];
    }

    public function test_recepcao_gerente_e_dono_cadastram_cliente(): void
    {
        $cpfs = ['52998224725', '11144477735', '39053344705'];
        foreach ([$this->recepcao, $this->gerente, $this->dono] as $i => $u) {
            $this->actingAs($u, 'web')->get(route('panel.customers.create'))->assertOk()->assertSee('Novo cliente');
            $r = $this->actingAs($u, 'web')->post(route('panel.customers.store'), $this->dados([
                'name' => "Cliente {$i}", 'cpf' => $cpfs[$i], 'phone' => '1197777000'.$i,
            ]));
            $c = Customer::query()->where('name', "Cliente {$i}")->sole();
            $r->assertRedirect(route('panel.customers.show', $c->public_id));
            $this->assertSame($cpfs[$i], $c->cpf);
        }
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index'))->assertSee(route('panel.customers.create'), false);
    }

    public function test_financeiro_e_profissional_recebem_403(): void
    {
        foreach ([$this->financeiro, $this->barbeiro] as $u) {
            $this->actingAs($u, 'web')->get(route('panel.customers.create'))->assertForbidden();
            $this->actingAs($u, 'web')->post(route('panel.customers.store'), $this->dados())->assertForbidden();
        }
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_cpf_obrigatorio_valido_e_sem_dono(): void
    {
        $existente = Customer::factory()->create(['name' => 'Cliente Que Já Existe', 'cpf' => '52998224725']);
        $url = route('panel.customers.store');

        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['cpf' => '']))->assertSessionHasErrors('cpf');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['cpf' => '123.456.789-00']))->assertSessionHasErrors('cpf');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['cpf' => '111.111.111-11']))->assertSessionHasErrors('cpf');
        $r = $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['cpf' => '529.982.247-25']))->assertSessionHasErrors('cpf');

        // Isolamento: a recusa nao mostra de quem e o CPF nem cria nada.
        $this->assertStringNotContainsString($existente->name, (string) session('errors')?->first('cpf'));
        $this->assertSame(1, Customer::query()->count());

        // CPF de cadastro excluido (soft delete) tambem nao volta a ser usado.
        $existente->delete();
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['cpf' => '529.982.247-25']))->assertSessionHasErrors('cpf');
    }

    public function test_celular_e_email_seguem_as_mesmas_validacoes_e_nao_repetem(): void
    {
        Customer::factory()->create(['phone' => '11977776666', 'email' => 'ja.existe@exemplo.test']);
        $url = route('panel.customers.store');

        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['phone' => '(11) 97777-6666']))->assertSessionHasErrors('phone');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['email' => 'nao-e-email']))->assertSessionHasErrors('email');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['email' => 'JA.EXISTE@exemplo.test']))->assertSessionHasErrors('email');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['name' => 'A']))->assertSessionHasErrors('name');
        $this->actingAs($this->recepcao, 'web')->post($url, $this->dados(['birth_date' => '2999-01-01']))->assertSessionHasErrors('birth_date');
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_cadastro_sem_senha_sem_confirmar_email_e_sem_consentimento_pela_equipe(): void
    {
        $this->actingAs($this->recepcao, 'web')->post(route('panel.customers.store'), $this->dados([
            'email' => 'Novo.Cliente@Exemplo.test',
            // Campos que a equipe nao define: ignorados.
            'password' => 'senha-escolhida-pela-equipe', 'password_confirmation' => 'senha-escolhida-pela-equipe',
            'email_verified_at' => now()->toDateTimeString(), 'marketing' => '1', 'marketing_email_consent' => 'granted', 'status' => 'inactive',
        ]))->assertSessionHasNoErrors();

        $c = Customer::query()->sole();
        $this->assertSame(['Cliente do Balcão', 'novo.cliente@exemplo.test', '+5511988887777', '1990-03-10'], [$c->name, $c->email, $c->phone, $c->birth_date?->format('Y-m-d')]);
        $this->assertFalse($c->hasPassword(), 'senha só o cliente cria');
        $this->assertNull($c->email_verified_at, 'e-mail só é confirmado pelo próprio cliente');
        $this->assertSame('unknown', $c->marketing_email_consent->value);
        $this->assertSame('active', $c->status->value);
        $this->assertNotNull($c->referral_code);
        Notification::assertSentTo($c, CustomerVerifyEmail::class);
        $this->assertFalse(Auth::guard('customer')->attempt(['email' => 'novo.cliente@exemplo.test', 'password' => 'senha-escolhida-pela-equipe']));

        // Aparece na hora na lista e na busca do balcao.
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['busca' => 'Balcão']))->assertSee('Cliente do Balcão');
        $this->assertCount(1, app(CustomerLookup::class)->search($this->recepcao, 'Balcão'));
    }

    public function test_sem_email_nenhum_aviso_sai(): void
    {
        $this->actingAs($this->recepcao, 'web')->post(route('panel.customers.store'), $this->dados())
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains((string) $s, 'sem e-mail'));
        $this->assertNull(Customer::query()->sole()->email);
        Notification::assertNothingSent();
    }

    public function test_criacao_vai_para_a_auditoria_com_cpf_mascarado(): void
    {
        $this->actingAs($this->recepcao, 'web')->post(route('panel.customers.store'), $this->dados())->assertSessionHasNoErrors();
        $c = Customer::query()->sole();

        $registro = AuditLog::query()->where('action', 'customer.registered')->sole();
        $this->assertSame([$this->recepcao->id, 'User', $c->id], [$registro->actor_id, $registro->actor_type, $registro->auditable_id]);
        $criado = AuditLog::query()->where('action', 'created')->where('auditable_type', 'Customer')->sole();
        $this->assertSame($this->recepcao->id, $criado->actor_id);
        $this->assertSame('529.***.***-25', $criado->new_values['cpf']);
        $this->assertStringNotContainsString('52998224725', (string) json_encode($criado->new_values));
    }

    public function test_cliente_usa_a_conta_depois_de_concluir_o_fluxo_de_senha(): void
    {
        $this->actingAs($this->recepcao, 'web')->post(route('panel.customers.store'), $this->dados(['email' => 'conta.balcao@exemplo.test']));
        $c = Customer::query()->sole();
        Auth::guard('web')->logout();

        // O mesmo "esqueci a senha" do site: prova o e-mail e cria a senha.
        $token = Password::broker('customers')->createToken($c);
        $this->post(route('customer.password.update'), [
            'token' => $token, 'email' => 'conta.balcao@exemplo.test',
            'password' => 'Senha-do-proprio-cliente-1', 'password_confirmation' => 'Senha-do-proprio-cliente-1',
        ])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertTrue($c->hasPassword());
        $this->assertNotNull($c->email_verified_at);
        $this->post(route('customer.login.attempt'), ['email' => 'conta.balcao@exemplo.test', 'password' => 'Senha-do-proprio-cliente-1'])->assertRedirect();
        $this->assertTrue(Auth::guard('customer')->check());
        $this->get(route('account.home'))->assertOk();
    }

    public function test_desativar_e_reativar_pela_equipe(): void
    {
        $c = Customer::factory()->create(['name' => 'Cliente Situação', 'email' => 'situacao@exemplo.test', 'password' => 'Senha-ficticia-123', 'email_verified_at' => now()]);

        foreach ([$this->financeiro, $this->barbeiro] as $u) {
            $this->actingAs($u, 'web')->post(route('panel.customers.status', $c->public_id), ['active' => 0])->assertForbidden();
        }

        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.show', $c->public_id))->assertSee('Desativar o cadastro?');
        $this->actingAs($this->recepcao, 'web')->post(route('panel.customers.status', $c->public_id), ['active' => 0])
            ->assertRedirect(route('panel.customers.show', $c->public_id));
        $this->assertSame('inactive', $c->fresh()->status->value);
        $log = AuditLog::query()->where('auditable_type', 'Customer')->where('auditable_id', $c->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame([$this->recepcao->id, 'inactive'], [$log->actor_id, $log->new_values['status']]);

        // Efeitos que o sistema ja tinha para inativo: sem login e fora da busca do balcao.
        $this->assertCount(0, app(CustomerLookup::class)->search($this->recepcao, 'Situação'));
        Auth::guard('web')->logout();
        $this->post(route('customer.login.attempt'), ['email' => 'situacao@exemplo.test', 'password' => 'Senha-ficticia-123']);
        $this->assertFalse(Auth::guard('customer')->check());

        $this->actingAs($this->gerente, 'web')->post(route('panel.customers.status', $c->public_id), ['active' => 1])->assertRedirect();
        $this->assertSame('active', $c->fresh()->status->value);
        $this->assertCount(1, app(CustomerLookup::class)->search($this->gerente, 'Situação'));
    }

    public function test_cadastro_anonimizado_nao_muda_de_situacao(): void
    {
        $c = Customer::factory()->create();
        app(CustomerErasure::class)->erase($c, $this->dono);

        $this->actingAs($this->dono, 'web')->post(route('panel.customers.status', $c->public_id), ['active' => 1])->assertForbidden();
        $this->assertSame('inactive', $c->fresh()->status->value);
    }
}

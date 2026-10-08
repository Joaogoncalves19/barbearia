<?php

namespace Tests\Feature\Customers;

use App\Modules\Customers\Enums\NoteVisibility;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Tela Clientes do painel (Fase 13, P13-01): a matriz de permissoes e a
 * CustomerPolicy continuam sendo a autoridade (nada de acesso novo), CPF
 * mascarado para quem nao tem customers.view_cpf, edicao auditada e
 * anonimizacao pelo mesmo servico da conta do cliente.
 */
class PanelCustomersTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private User $dono;

    private User $gerente;

    private User $financeiro;

    private User $barbeiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Mail::fake();
        Notification::fake();
        $this->dono = User::factory()->role(StaffRole::Owner)->create();
        $this->gerente = User::factory()->manager()->create();
        $this->financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->barbeiro = User::factory()->role(StaffRole::Professional)->create();
        $this->joao->forceFill(['user_id' => $this->barbeiro->id])->save();
        $this->cliente->forceFill(['cpf' => '52998224725', 'phone' => '11988887777', 'email' => 'cliente.ficticio@exemplo.test'])->save();
    }

    private function concluido(Customer $customer, string $time = '10:00'): void
    {
        $ag = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $customer,
        ));
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);
        $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
    }

    public function test_so_quem_ve_clientes_abre_a_tela_e_o_menu_acompanha(): void
    {
        foreach ([$this->dono, $this->gerente, $this->recepcao] as $u) {
            $this->actingAs($u, 'web')->get(route('panel.customers.index'))->assertOk()->assertSee('Cliente Fictício');
            $this->actingAs($u, 'web')->get(route('panel.customers.show', $this->cliente->public_id))->assertOk();
            $this->actingAs($u, 'web')->get(route('panel.home'))->assertSee(route('panel.customers.index'), false);
        }

        // Financeiro: sem clientes (matriz). Profissional: so pela area dele.
        foreach ([$this->financeiro, $this->barbeiro] as $u) {
            $this->actingAs($u, 'web')->get(route('panel.customers.index'))->assertForbidden();
            $this->actingAs($u, 'web')->get(route('panel.customers.show', $this->cliente->public_id))->assertForbidden();
            $this->actingAs($u, 'web')->put(route('panel.customers.update', $this->cliente->public_id), ['name' => 'Invasor'])->assertForbidden();
        }
        $this->actingAs($this->financeiro, 'web')->get(route('panel.home'))->assertDontSee(route('panel.customers.index'), false);
        $this->assertSame('Cliente Fictício', $this->cliente->fresh()->name);

    }

    public function test_busca_por_nome_celular_e_cpf_so_para_quem_ve_o_cpf(): void
    {
        Customer::factory()->create(['name' => 'Outro Cliente', 'phone' => '11977776666']);

        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['busca' => 'Fictício']))
            ->assertSee('Cliente Fictício')->assertDontSee('Outro Cliente');
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['busca' => '7777-6666']))
            ->assertSee('Outro Cliente')->assertDontSee('Cliente Fictício');

        // CPF completo na busca so com customers.view_cpf (senao revelaria o numero).
        $this->actingAs($this->gerente, 'web')->get(route('panel.customers.index', ['busca' => '529.982.247-25']))
            ->assertSee('Cliente Fictício')->assertDontSee('Outro Cliente');
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['busca' => '529.982.247-25']))
            ->assertDontSee('Cliente Fictício');
    }

    public function test_filtro_de_situacao_e_mesclados_fora_da_lista(): void
    {
        $inativo = Customer::factory()->create(['name' => 'Cliente Inativo']);
        $inativo->forceFill(['status' => 'inactive'])->save();
        $mesclado = Customer::factory()->create(['name' => 'Cliente Mesclado']);
        $mesclado->forceFill(['merged_into_customer_id' => $this->cliente->id])->save();

        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index'))
            ->assertSee('Cliente Fictício')->assertDontSee('Cliente Inativo')->assertDontSee('Cliente Mesclado');
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['situacao' => 'inativos']))
            ->assertSee('Cliente Inativo')->assertDontSee('Cliente Fictício');
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.index', ['situacao' => 'invalida']))
            ->assertOk()->assertSee('Cliente Fictício');
    }

    public function test_ficha_mostra_historico_beneficios_e_anotacoes(): void
    {
        $this->concluido($this->cliente);
        $this->book($this->terca, '15:00', customer: $this->cliente);
        $this->activeSubscription($this->cliente);
        CustomerNote::query()->create(['customer_id' => $this->cliente->id, 'author_label' => 'João', 'visibility' => NoteVisibility::Professionals, 'body' => 'Prefere máquina 2 nas laterais']);

        $r = $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.show', $this->cliente->public_id))->assertOk();
        $r->assertSee('+5511988887777')->assertSee('cliente.ficticio@exemplo.test');
        $r->assertSee('Prefere máquina 2 nas laterais');
        $r->assertSee('Clube do Corte');
        $r->assertSee('data-attendance-row', false);
        $this->assertSame(2, substr_count($r->getContent(), 'data-appointment-row'));
        $r->assertSee('data-customer-points', false);
        $r->assertDontSee('Anonimizar cadastro');
    }

    public function test_cpf_mascarado_para_recepcao_e_completo_para_quem_pode(): void
    {
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.show', $this->cliente->public_id))
            ->assertSee('529.***.***-25')->assertDontSee('529.982.247-25')->assertDontSee('52998224725');
        $this->actingAs($this->recepcao, 'web')->get(route('panel.customers.edit', $this->cliente->public_id))
            ->assertOk()->assertDontSee('52998224725')->assertDontSee('name="cpf"', false);

        $this->actingAs($this->gerente, 'web')->get(route('panel.customers.show', $this->cliente->public_id))->assertSee('529.982.247-25');
    }

    public function test_edicao_auditada_e_campos_fora_do_alcance_da_equipe(): void
    {
        $this->actingAs($this->recepcao, 'web')->put(route('panel.customers.update', $this->cliente->public_id), [
            'name' => 'Cliente Renomeado', 'phone' => '(11) 96666-5555', 'birth_date' => '1991-04-12',
            'email' => 'outro@exemplo.test', 'status' => 'inactive', 'marketing_email_consent' => 'granted',
        ])->assertRedirect(route('panel.customers.show', $this->cliente->public_id));

        $c = $this->cliente->fresh();
        $this->assertSame(['Cliente Renomeado', '+5511966665555', '1991-04-12'], [$c->name, $c->phone, $c->birth_date?->format('Y-m-d')]);
        $this->assertSame('cliente.ficticio@exemplo.test', $c->email, 'e-mail e acesso do cliente: a equipe não troca');
        $this->assertSame('active', $c->status->value);
        $this->assertSame('unknown', $c->marketing_email_consent->value);

        $log = AuditLog::query()->where('auditable_type', 'Customer')->where('auditable_id', $c->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame([$this->recepcao->id, 'User'], [$log->actor_id, $log->actor_type]);
        $this->assertSame('Cliente Renomeado', $log->new_values['name']);

        // CPF: recepcao nao tem customers.view_cpf -> pedido inteiro recusado.
        $this->actingAs($this->recepcao, 'web')->put(route('panel.customers.update', $this->cliente->public_id), [
            'name' => 'Tentativa', 'cpf' => '11144477735',
        ])->assertForbidden();
        $this->assertSame(['Cliente Renomeado', '52998224725'], [$this->cliente->fresh()->name, $this->cliente->fresh()->cpf]);
    }

    public function test_gerente_corrige_cpf_com_validacao_e_sem_duplicar(): void
    {
        $outro = Customer::factory()->create(['cpf' => '11144477735']);
        $url = route('panel.customers.update', $this->cliente->public_id);

        $this->actingAs($this->gerente, 'web')->put($url, ['name' => 'Cliente Fictício', 'cpf' => '123.456.789-00'])->assertSessionHasErrors('cpf');
        $this->actingAs($this->gerente, 'web')->put($url, ['name' => 'Cliente Fictício', 'cpf' => $outro->cpf])->assertSessionHasErrors('cpf');
        $this->actingAs($this->gerente, 'web')->put($url, ['name' => 'Cliente Fictício', 'cpf' => ''])->assertSessionHasErrors('cpf');
        $this->actingAs($this->gerente, 'web')->put($url, ['name' => 'Cliente Fictício', 'cpf' => '390.533.447-05'])->assertSessionHasNoErrors();
        $this->assertSame('39053344705', $this->cliente->fresh()->cpf);

        $log = AuditLog::query()->where('auditable_type', 'Customer')->where('auditable_id', $this->cliente->id)->latest('id')->firstOrFail();
        $this->assertSame('390.***.***-05', $log->new_values['cpf'], 'CPF mascarado na auditoria');
    }

    public function test_celular_invalido_ou_de_outro_cliente_e_recusado(): void
    {
        Customer::factory()->create(['phone' => '11955554444']);
        $url = route('panel.customers.update', $this->cliente->public_id);

        $this->actingAs($this->recepcao, 'web')->put($url, ['name' => 'Cliente Fictício', 'phone' => '123'])->assertSessionHasErrors('phone');
        $this->actingAs($this->recepcao, 'web')->put($url, ['name' => 'Cliente Fictício', 'phone' => '(11) 95555-4444'])->assertSessionHasErrors('phone');
        $this->assertSame('+5511988887777', $this->cliente->fresh()->phone);
    }

    public function test_anonimizacao_so_do_proprietario_com_senha_e_palavra_de_confirmacao(): void
    {
        $this->concluido($this->cliente);
        $confirmar = route('panel.customers.anonymize.confirm', $this->cliente->public_id);
        $enviar = route('panel.customers.anonymize', $this->cliente->public_id);

        foreach ([$this->gerente, $this->recepcao] as $u) {
            $this->actingAs($u, 'web')->get($confirmar)->assertForbidden();
            $this->actingAs($u, 'web')->withSession(['auth.password_confirmed_at' => time()])->post($enviar, ['confirmacao' => 'ANONIMIZAR'])->assertForbidden();
        }
        $this->flushSession(); // a sessao do teste e compartilhada: sem senha reconfirmada daqui em diante

        $this->actingAs($this->dono, 'web')->get(route('panel.customers.show', $this->cliente->public_id))->assertSee('Anonimizar cadastro');
        $this->actingAs($this->dono, 'web')->get($confirmar)->assertRedirect(route('panel.password.confirm'));
        $this->actingAs($this->dono, 'web')->post($enviar, ['confirmacao' => 'ANONIMIZAR'])->assertForbidden();
        $this->assertNull($this->cliente->fresh()->anonymized_at);

        $comSenha = $this->actingAs($this->dono, 'web')->withSession(['auth.password_confirmed_at' => time()]);
        $comSenha->get($confirmar)->assertOk()->assertSee('Digite ANONIMIZAR');
        $comSenha->post($enviar, ['confirmacao' => 'anonimizar'])->assertSessionHasErrors('confirmacao');
        $this->assertNull($this->cliente->fresh()->anonymized_at);

        $comSenha->post($enviar, ['confirmacao' => 'ANONIMIZAR'])->assertRedirect(route('panel.customers.show', $this->cliente->public_id));
        $c = $this->cliente->fresh();
        $this->assertNotNull($c->anonymized_at);
        $this->assertSame(['Cliente removido', null, null, null], [$c->name, $c->email, $c->phone, $c->cpf]);
        $this->assertSame(1, $c->appointments()->count(), 'o histórico fica');
        $log = AuditLog::query()->where('action', 'customer.anonymized')->sole();
        $this->assertSame([$this->dono->id, 'User'], [$log->actor_id, $log->actor_type]);

        // Depois: ficha sem dados, sem editar e sem anonimizar de novo.
        $this->actingAs($this->dono, 'web')->get(route('panel.customers.show', $c->public_id))->assertOk()->assertSee('Cadastro anonimizado')->assertDontSee('Anonimizar cadastro');
        $this->actingAs($this->dono, 'web')->get(route('panel.customers.edit', $c->public_id))->assertForbidden();
        $comSenha->post($enviar, ['confirmacao' => 'ANONIMIZAR'])->assertForbidden();
        $this->actingAs($this->dono, 'web')->get(route('panel.customers.index', ['situacao' => 'removidos']))->assertSee('Cliente removido');
    }

    public function test_anonimizacao_bloqueada_com_horario_marcado_mostra_o_motivo_para_a_equipe(): void
    {
        $this->book($this->terca, '15:00', customer: $this->cliente);
        $comSenha = $this->actingAs($this->dono, 'web')->withSession(['auth.password_confirmed_at' => time()]);

        $comSenha->get(route('panel.customers.anonymize.confirm', $this->cliente->public_id))
            ->assertOk()->assertSee('O cliente tem horário marcado')->assertDontSee('Digite ANONIMIZAR');
        $comSenha->post(route('panel.customers.anonymize', $this->cliente->public_id), ['confirmacao' => 'ANONIMIZAR'])
            ->assertSessionHasErrors('anonymize');
        $this->assertNull($this->cliente->fresh()->anonymized_at);
    }
}

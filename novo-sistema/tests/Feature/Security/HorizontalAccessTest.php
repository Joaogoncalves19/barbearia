<?php

namespace Tests\Feature\Security;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerMergeCandidate;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Database\Factories\CustomerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Acesso HORIZONTAL (IDOR): trocar o id/codigo na URL, mandar ids no
 * formulario ou chamar o endpoint direto nunca da acesso ao registro de
 * outra pessoa. Registro alheio responde 404 (nao confirma que existe).
 */
class HorizontalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function barbeiro(string $nome): User
    {
        $user = User::factory()->role(StaffRole::Professional)->create(['username' => $nome]);
        Professional::factory()->create(['user_id' => $user->id, 'display_name' => ucfirst($nome)]);

        return $user->fresh();
    }

    // --- Cliente x cliente ---------------------------------------------------------------

    public function test_cliente_ve_o_proprio_agendamento_e_nunca_o_de_outro(): void
    {
        $ana = Customer::factory()->create();
        $bia = Customer::factory()->create();
        $daAna = Appointment::factory()->create(['customer_id' => $ana->id, 'customer_name' => $ana->name]);
        $daBia = Appointment::factory()->create(['customer_id' => $bia->id, 'customer_name' => $bia->name]);

        $this->actingAs($ana, 'customer')->get(route('account.appointments.show', $daAna))->assertOk()->assertSee($daAna->code);
        $this->actingAs($ana, 'customer')->get(route('account.appointments.show', $daBia))->assertNotFound();
        $this->actingAs($ana, 'customer')->get('/minha-conta/agendamentos/AG-NAOEXISTE')->assertNotFound();
    }

    public function test_lista_da_conta_mostra_so_os_agendamentos_do_cliente(): void
    {
        $ana = Customer::factory()->create();
        $bia = Customer::factory()->create();
        $daAna = Appointment::factory()->create(['customer_id' => $ana->id]);
        $daBia = Appointment::factory()->create(['customer_id' => $bia->id]);

        $this->actingAs($ana, 'customer')->get(route('account.home'))
            ->assertOk()->assertSee($daAna->code)->assertDontSee($daBia->code);
    }

    public function test_formulario_de_dados_altera_so_o_proprio_cadastro_mesmo_com_ids_enviados(): void
    {
        $ana = Customer::factory()->create(['name' => 'Ana']);
        $bia = Customer::factory()->create(['name' => 'Bia']);

        $this->actingAs($ana, 'customer')->put(route('account.profile.update'), [
            'name' => 'Ana Nova', 'id' => $bia->id, 'customer_id' => $bia->id, 'public_id' => $bia->public_id,
        ])->assertRedirect();

        $this->assertSame('Ana Nova', $ana->fresh()->name);
        $this->assertSame('Bia', $bia->fresh()->name);
    }

    public function test_cliente_nao_altera_cpf_e_mail_status_nem_consentimento_pelo_formulario(): void
    {
        $ana = Customer::factory()->create();
        $cpf = $ana->cpf;
        $email = $ana->email;

        $this->actingAs($ana, 'customer')->put(route('account.profile.update'), [
            'name' => 'Ana', 'cpf' => CustomerFactory::fakeCpf(), 'email' => 'invasor@exemplo.test',
            'status' => 'inactive', 'marketing_email_consent' => 'granted', 'merged_into_customer_id' => 1,
        ])->assertRedirect();

        $ana->refresh();
        $this->assertSame($cpf, $ana->cpf);
        $this->assertSame($email, $ana->email);
        $this->assertSame('active', $ana->status->value);
        $this->assertSame('unknown', $ana->marketing_email_consent->value);
    }

    public function test_celular_de_outro_cliente_e_recusado(): void
    {
        $ana = Customer::factory()->create();
        $bia = Customer::factory()->create(['phone' => '+5511977776666']);

        $this->actingAs($ana, 'customer')->put(route('account.profile.update'), ['name' => 'Ana', 'phone' => '(11) 97777-6666'])
            ->assertSessionHasErrors('phone');
        $this->assertSame('+5511977776666', $bia->fresh()->phone);
    }

    // --- CPF obrigatorio -------------------------------------------------------------------

    public function test_cliente_sem_cpf_precisa_informar_antes_de_usar_a_conta(): void
    {
        $ana = Customer::factory()->withoutCpf()->create();

        foreach (['account.home', 'account.profile.edit', 'account.password.edit'] as $rota) {
            $this->actingAs($ana, 'customer')->get(route($rota))->assertRedirect(route('account.complete.edit'));
        }

        $this->actingAs($ana, 'customer')->put(route('account.complete.update'), ['cpf' => '123'])->assertSessionHasErrors('cpf');

        $cpf = CustomerFactory::fakeCpf();
        $this->actingAs($ana, 'customer')->put(route('account.complete.update'), ['cpf' => $cpf])->assertRedirect(route('account.home'));
        $this->assertSame($cpf, $ana->fresh()->cpf);
        $this->actingAs($ana->fresh(), 'customer')->get(route('account.home'))->assertOk();
    }

    public function test_cpf_de_outro_cadastro_nao_mescla_e_vai_para_revisao(): void
    {
        $bia = Customer::factory()->create();
        $ana = Customer::factory()->withoutCpf()->create();

        $this->actingAs($ana, 'customer')->put(route('account.complete.update'), ['cpf' => $bia->cpf])->assertSessionHasErrors('cpf');

        $this->assertNull($ana->fresh()->cpf);
        $candidato = CustomerMergeCandidate::query()->sole();
        $this->assertSame([$bia->id, $ana->id, 'cpf'], [$candidato->customer_id, $candidato->duplicate_customer_id, $candidato->match_field]);
        $this->assertStringNotContainsString($bia->cpf, (string) $candidato->match_value, 'CPF guardado mascarado');
    }

    public function test_cpf_aparece_mascarado_na_conta(): void
    {
        $ana = Customer::factory()->create(['cpf' => '52998224725']);

        // Fase 12: o CPF fica em "Meus dados"; nenhuma tela da conta mostra o numero inteiro.
        $this->actingAs($ana, 'customer')->get(route('account.profile.edit'))->assertSee('529.***.***-25')->assertDontSee('52998224725');
        foreach (['account.home', 'account.privacy', 'account.appointments.index', 'account.receipts.index'] as $rota) {
            $this->actingAs($ana, 'customer')->get(route($rota))->assertOk()->assertDontSee('52998224725');
        }
    }

    // --- Profissional x profissional ------------------------------------------------------

    public function test_profissional_ve_a_propria_ficha_e_nao_a_de_outro(): void
    {
        $carlos = $this->barbeiro('carlos');
        $davi = $this->barbeiro('davi');

        // Fase 12.5: a propria ficha abre no Perfil da area do profissional.
        $this->actingAs($carlos, 'web')->get(route('panel.professionals.show', $carlos->professional))->assertRedirect(route('pro.profile'));
        $this->actingAs($carlos, 'web')->get(route('pro.profile'))->assertOk()->assertSee('Carlos');
        $this->actingAs($carlos, 'web')->get(route('panel.professionals.show', $davi->professional))->assertNotFound();
        $this->actingAs($carlos, 'web')->get('/painel/profissionais/999999')->assertNotFound();
    }

    public function test_quem_ve_a_equipe_abre_qualquer_ficha(): void
    {
        $davi = $this->barbeiro('davi');

        foreach ([StaffRole::Owner, StaffRole::Manager, StaffRole::Reception] as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'web')
                ->get(route('panel.professionals.show', $davi->professional))->assertOk();
        }
        $this->actingAs(User::factory()->role(StaffRole::Finance)->create(), 'web')
            ->get(route('panel.professionals.show', $davi->professional))->assertNotFound();
    }

    public function test_profissional_ve_so_a_propria_agenda_e_os_proprios_clientes(): void
    {
        $carlos = $this->barbeiro('carlos');
        $davi = $this->barbeiro('davi');
        $clienteDoCarlos = Customer::factory()->create();
        $clienteDoDavi = Customer::factory()->create();
        $agCarlos = Appointment::factory()->create(['professional_id' => $carlos->professional->id, 'customer_id' => $clienteDoCarlos->id]);
        $agDavi = Appointment::factory()->create(['professional_id' => $davi->professional->id, 'customer_id' => $clienteDoDavi->id]);

        $gate = Gate::forUser($carlos);
        $this->assertTrue($gate->allows('view', $agCarlos));
        $this->assertTrue($gate->allows('update', $agCarlos));
        $this->assertFalse($gate->allows('view', $agDavi));
        $this->assertFalse($gate->allows('update', $agDavi));
        $this->assertFalse($gate->allows('cancel', $agDavi));

        $this->assertTrue($gate->allows('view', $clienteDoCarlos));
        $this->assertFalse($gate->allows('view', $clienteDoDavi));
        $this->assertFalse($gate->allows('update', $clienteDoCarlos), 'ver nao e editar');
        $this->assertFalse($gate->allows('viewCpf', $clienteDoCarlos));
    }

    // --- Matriz das policies por papel ------------------------------------------------------

    public function test_matriz_das_policies_de_cliente_e_agendamento_por_papel(): void
    {
        $cliente = Customer::factory()->create();
        $ag = Appointment::factory()->create(['customer_id' => $cliente->id]);

        $esperado = [
            // papel => [ver cliente, editar, ver CPF, anonimizar, ver agendamento, alterar, cancelar]
            'owner' => [true, true, true, true, true, true, true],
            'manager' => [true, true, true, false, true, true, true],
            'reception' => [true, true, false, false, true, true, true],
            'finance' => [false, false, false, false, false, false, false],
        ];

        foreach ($esperado as $papel => $linha) {
            $g = Gate::forUser(User::factory()->role(StaffRole::from($papel))->create());
            $obtido = [
                $g->allows('view', $cliente), $g->allows('update', $cliente), $g->allows('viewCpf', $cliente), $g->allows('anonymize', $cliente),
                $g->allows('view', $ag), $g->allows('update', $ag), $g->allows('cancel', $ag),
            ];
            $this->assertSame($linha, $obtido, "papel {$papel}");
        }
    }

    public function test_cliente_remarca_e_cancela_so_os_proprios_e_futuros(): void
    {
        // Fase 5: o cliente passou a remarcar/cancelar os proprios (prazos no BookingService).
        $cliente = Customer::factory()->create();
        $outro = Customer::factory()->create();
        $futuro = Appointment::factory()->create(['customer_id' => $cliente->id]);
        $alheio = Appointment::factory()->create(['customer_id' => $outro->id]);
        $passado = Appointment::factory()->create(['customer_id' => $cliente->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);

        $g = Gate::forUser($cliente);
        $this->assertTrue($g->allows('view', $futuro));
        $this->assertTrue($g->allows('reschedule', $futuro));
        $this->assertTrue($g->allows('cancel', $futuro));
        $this->assertFalse($g->allows('update', $futuro), 'observacoes e status sao da equipe');
        $this->assertFalse($g->allows('reschedule', $alheio));
        $this->assertFalse($g->allows('cancel', $alheio));
        $this->assertFalse($g->allows('cancel', $passado), 'ja aconteceu');
    }

    // --- Equipe: conta propria -------------------------------------------------------------

    public function test_minha_conta_da_equipe_nao_permite_se_promover_nem_trocar_login(): void
    {
        $user = User::factory()->create(['username' => 'recepcao1', 'email' => 'r1@barbearia.test']);

        $this->actingAs($user, 'web')->put(route('panel.account.update'), [
            'name' => 'Novo Nome', 'role' => 'owner', 'is_active' => '1', 'username' => 'dono', 'email' => 'dono@barbearia.test',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Novo Nome', $user->name);
        $this->assertSame(StaffRole::Reception, $user->role);
        $this->assertSame('recepcao1', $user->username);
        $this->assertSame('r1@barbearia.test', $user->email);
    }
}

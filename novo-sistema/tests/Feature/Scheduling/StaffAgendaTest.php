<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Models\TimeOff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * Agenda da equipe: consultar x administrar, profissional isolado, criacao,
 * remarcacao, cancelamento e configuracao (expediente, folgas, bloqueios).
 */
class StaffAgendaTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    private User $recepcao;

    private User $barbeiroJoao;

    private Professional $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
        $this->recepcao = User::factory()->create();
        $this->barbeiroJoao = User::factory()->role(StaffRole::Professional)->create();
        $this->joao->update(['user_id' => $this->barbeiroJoao->id]);
        $this->maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->maria->services()->attach($this->corte->id);
    }

    // --- Consultar ------------------------------------------------------------------------------

    public function test_recepcao_ve_a_agenda_de_todos(): void
    {
        $a = $this->book($this->terca, '14:00');
        $b = $this->book($this->terca, '15:00', $this->maria);

        $this->actingAs($this->recepcao, 'web')->get(route('panel.agenda', ['data' => $this->terca]))
            ->assertOk()->assertSee($a->customer_name)->assertSee($b->customer_name)->assertSee('14:00–14:30'); // redesign: linha do tempo mostra inicio e fim
    }

    public function test_profissional_ve_so_a_propria_agenda_mesmo_manipulando_o_filtro(): void
    {
        $meu = $this->book($this->terca, '14:00');
        $daMaria = $this->book($this->terca, '15:00', $this->maria);

        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.agenda', ['data' => $this->terca, 'profissional' => $this->maria->id]))
            ->assertOk()->assertSee($meu->customer_name)->assertDontSee($daMaria->customer_name)->assertDontSee('Novo agendamento de Maria');

        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.appointments.show', $meu))->assertOk();
        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.appointments.show', $daMaria))->assertNotFound();
        $this->actingAs($this->barbeiroJoao, 'web')->post(route('panel.appointments.cancel', $daMaria))->assertForbidden();
        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.appointments.reschedule', $daMaria))->assertForbidden();
        $this->assertSame(AppointmentStatus::Confirmed, $daMaria->fresh()->status);
    }

    public function test_financeiro_e_cliente_nao_acessam_a_agenda(): void
    {
        $a = $this->book($this->terca, '14:00');

        $this->actingAs(User::factory()->role(StaffRole::Finance)->create(), 'web')->get(route('panel.agenda'))->assertForbidden();
        $this->actingAs(User::factory()->role(StaffRole::Finance)->create(), 'web')->get(route('panel.appointments.show', $a))->assertNotFound();
    }

    public function test_cliente_e_visitante_nao_acessam_a_agenda(): void
    {
        $this->get(route('panel.appointments.create'))->assertRedirect(route('staff.login'));
        $this->actingAs($this->cliente, 'customer')->get(route('panel.agenda'))->assertRedirect(route('staff.login'));
    }

    // --- Criar -----------------------------------------------------------------------------------

    public function test_recepcao_agenda_cliente_cadastrado_e_sem_cadastro(): void
    {
        $this->actingAs($this->recepcao, 'web')->get(route('panel.appointments.create', ['servico' => $this->corte->id, 'profissional' => $this->joao->id, 'data' => $this->segunda, 'cliente' => 'Fictício']))
            ->assertOk()->assertSee('value="09:00"', false)->assertSee('Cliente Fictício');

        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'data' => $this->segunda, 'hora' => '09:00', 'customer_id' => $this->cliente->id,
        ])->assertRedirect();

        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->maria->id, 'data' => $this->segunda, 'hora' => '09:00', 'contact_name' => 'Passante',
        ])->assertRedirect();

        $this->assertSame(['Cliente Fictício', 'Passante'], Appointment::query()->orderBy('id')->pluck('customer_name')->all());
        $this->assertSame($this->recepcao->id, Appointment::query()->first()->created_by_user_id);
    }

    public function test_opcao_do_servico_envia_o_id_do_servico(): void
    {
        // Regressao: o value da opcao era a POSICAO na lista (flatMap renumera
        // as chaves) e agendava o servico errado. Pego pelo teste de navegador.
        Service::factory()->count(3)->create();
        $barba = Service::factory()->create(['name' => 'Barba ZZ']);

        $html = $this->actingAs($this->recepcao, 'web')->get(route('panel.appointments.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.$barba->id.'"[^>]*>Barba ZZ/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$this->corte->id.'"[^>]*>Corte/', $html);
    }

    public function test_criar_sem_cliente_ou_em_horario_ocupado_nao_grava(): void
    {
        $this->book($this->terca, '14:00');
        $base = ['service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'data' => $this->terca, 'hora' => '14:15'];

        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.store'), $base + ['contact_name' => 'Xico'])->assertSessionHasErrors('slot');
        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.store'), array_merge($base, ['hora' => '16:00']))->assertSessionHasErrors('contact_name');

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_profissional_so_agenda_na_propria_agenda(): void
    {
        $base = ['service_id' => $this->corte->id, 'data' => $this->terca, 'hora' => '16:00', 'contact_name' => 'Amigo'];

        $this->actingAs($this->barbeiroJoao, 'web')->post(route('panel.appointments.store'), $base + ['professional_id' => $this->maria->id])->assertForbidden();
        $this->actingAs($this->barbeiroJoao, 'web')->post(route('panel.appointments.store'), $base + ['professional_id' => $this->joao->id])->assertRedirect();

        $this->assertSame([$this->joao->id], Appointment::query()->pluck('professional_id')->all());
    }

    public function test_profissional_nao_busca_clientes_de_outros(): void
    {
        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.appointments.create', ['servico' => $this->corte->id, 'profissional' => $this->joao->id, 'cliente' => 'Fictício']))
            ->assertOk()->assertDontSee('Cliente Fictício');

        $this->actingAs($this->barbeiroJoao, 'web')->post(route('panel.appointments.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'data' => $this->terca, 'hora' => '16:00', 'customer_id' => $this->cliente->id,
        ])->assertNotFound();
    }

    // --- Alterar, remarcar, cancelar -----------------------------------------------------------

    public function test_recepcao_remarca_cancela_e_registra_falta(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $this->actingAs($this->recepcao, 'web')->put(route('panel.appointments.reschedule.update', $a), ['professional_id' => $this->maria->id, 'data' => $this->terca, 'hora' => '17:00'])
            ->assertRedirect(route('panel.appointments.show', $a));
        $this->assertSame($this->maria->id, $a->fresh()->professional_id);

        $this->actingAs($this->recepcao, 'web')->put(route('panel.appointments.notes', $a), ['notes' => 'Chega atrasado'])->assertRedirect();
        $this->assertSame('Chega atrasado', $a->fresh()->notes);

        $this->actingAs($this->recepcao, 'web')->get(route('panel.appointments.show', $a))
            ->assertOk()->assertSee('Agendamento remarcado.')->assertSee('com Maria');

        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.cancel', $a), ['reason' => 'Cliente pediu'])->assertRedirect();
        $this->assertSame(AppointmentStatus::Cancelled, $a->fresh()->status);
        $this->assertSame('Cliente pediu', $a->fresh()->cancellation_reason);

        $b = $this->book($this->segunda, '10:00');
        $this->travelTo($this->at($this->segunda, '10:40'));
        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.no-show', $b))->assertRedirect();
        $this->assertSame(AppointmentStatus::NoShow, $b->fresh()->status);
    }

    public function test_confirmacao_manual_pelo_painel(): void
    {
        BookingPolicy::save(['requires_confirmation' => true] + BookingPolicy::current()->toArray());
        $a = $this->book($this->terca, '14:00');

        $this->actingAs($this->recepcao, 'web')->post(route('panel.appointments.confirm', $a))->assertRedirect();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
    }

    // --- Configuracao ---------------------------------------------------------------------------

    public function test_so_quem_configura_altera_funcionamento_e_regras(): void
    {
        $horas = ['hours' => [2 => [['start' => '10:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '18:00']]], 'policy' => BookingPolicy::current()->toArray()];

        $this->actingAs($this->recepcao, 'web')->put(route('panel.schedule.settings.update'), $horas)->assertForbidden();
        $this->actingAs($this->barbeiroJoao, 'web')->get(route('panel.schedule.settings'))->assertForbidden();

        $gerente = User::factory()->manager()->create();
        $this->actingAs($gerente, 'web')->get(route('panel.schedule.settings'))->assertOk();
        $this->actingAs($gerente, 'web')->put(route('panel.schedule.settings.update'), $horas)->assertRedirect(route('panel.schedule.settings'));

        $this->assertSame(2, BusinessHour::query()->count(), 'só terça, em dois períodos');
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '12:30'));
        $this->assertSame('outside_hours', $this->reasonAt('2026-10-07', '10:00'), 'quarta fechada');
        $this->assertTrue(AuditLog::query()->where('action', 'agenda.business_hours_changed')->where('actor_id', $gerente->id)->exists());
    }

    public function test_regras_invalidas_sao_recusadas(): void
    {
        $gerente = User::factory()->manager()->create();
        $politica = ['slot_step_minutes' => 7] + BookingPolicy::current()->toArray();

        $this->actingAs($gerente, 'web')->put(route('panel.schedule.settings.update'), ['hours' => [], 'policy' => $politica])->assertSessionHasErrors('policy.slot_step_minutes');
        $this->actingAs($gerente, 'web')->put(route('panel.schedule.settings.update'), ['hours' => [1 => [['start' => '18:00', 'end' => '09:00']]], 'policy' => BookingPolicy::current()->toArray()])
            ->assertSessionHasErrors('hours.1.0.start');
        $this->assertSame(7, BusinessHour::query()->count(), 'nada mudou');
    }

    public function test_expediente_e_pausas_do_profissional(): void
    {
        $gerente = User::factory()->manager()->create();

        $this->actingAs($this->recepcao, 'web')->put(route('panel.schedule.working-hours.update', $this->joao), ['mode' => 'shop'])->assertForbidden();

        $this->actingAs($gerente, 'web')->put(route('panel.schedule.working-hours.update', $this->joao), [
            'mode' => 'own', 'days' => [2 => ['works' => '1', 'start' => '12:00', 'end' => '18:00'], 3 => ['works' => '0']],
        ])->assertRedirect();
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '10:00'));
        $this->assertNull($this->reasonAt($this->terca, '12:00'));

        $this->actingAs($gerente, 'web')->post(route('panel.schedule.breaks.store', $this->joao), ['start' => '14:00', 'end' => '15:00', 'label' => 'Almoço'])->assertRedirect();
        $this->assertSame('break', $this->reasonAt($this->terca, '14:00'));

        $pausa = $this->joao->breaks()->sole();
        $this->actingAs($gerente, 'web')->delete(route('panel.schedule.breaks.destroy', [$this->maria, $pausa]))->assertNotFound();
        $this->actingAs($gerente, 'web')->delete(route('panel.schedule.breaks.destroy', [$this->joao, $pausa]))->assertRedirect();
    }

    public function test_folga_e_bloqueio_nao_mexem_em_agendamento_existente(): void
    {
        $a = $this->book($this->terca, '14:00');

        $this->actingAs($this->recepcao, 'web')->post(route('panel.time-off.store'), [
            'professional_id' => $this->joao->id, 'starts_on' => $this->terca, 'ends_on' => $this->terca, 'kind' => 'medical', 'reason' => 'Consulta',
        ])->assertSessionHas('status', fn ($m) => str_contains($m, '1 agendamento(s)'));
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status, 'folga não cancela nada');
        $this->assertSame('time_off', $this->reasonAt($this->terca, '16:00'));

        $this->actingAs($this->recepcao, 'web')->post(route('panel.blocks.store'), [
            'date' => '2026-10-12', 'all_day' => '1', 'reason' => 'Feriado',
        ])->assertRedirect();
        $this->assertSame('blocked', $this->reasonAt('2026-10-12', '10:00', $this->maria));
        $this->assertNull(BlockedSlot::query()->sole()->professional_id, 'barbearia inteira');

        $this->actingAs($this->barbeiroJoao, 'web')->post(route('panel.blocks.store'), ['date' => '2026-10-13', 'all_day' => '1', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->barbeiroJoao, 'web')->delete(route('panel.time-off.destroy', TimeOff::query()->sole()))->assertForbidden();
    }

    public function test_folga_e_bloqueio_passados_ficam_como_historico(): void
    {
        $gerente = User::factory()->manager()->create();
        $this->actingAs($gerente, 'web')->post(route('panel.blocks.store'), ['date' => $this->segunda, 'start' => '09:00', 'end' => '10:00', 'reason' => 'Reunião'])->assertRedirect();
        $this->actingAs($gerente, 'web')->post(route('panel.time-off.store'), ['professional_id' => $this->joao->id, 'starts_on' => $this->segunda, 'ends_on' => $this->segunda, 'kind' => 'day_off'])->assertRedirect();

        $this->travelTo($this->at('2026-10-07', '10:00'));

        $this->actingAs($gerente, 'web')->delete(route('panel.blocks.destroy', BlockedSlot::query()->sole()))->assertForbidden();
        $this->actingAs($gerente, 'web')->delete(route('panel.time-off.destroy', TimeOff::query()->sole()))->assertForbidden();
        $this->actingAs($gerente, 'web')->post(route('panel.blocks.store'), ['date' => $this->segunda, 'start' => '09:00', 'end' => '10:00', 'reason' => 'No passado'])->assertSessionHasErrors('date');
    }

    public function test_telas_de_agenda_abrem_para_quem_pode(): void
    {
        $a = $this->book($this->terca, '14:00', customer: Customer::factory()->create());
        $gerente = User::factory()->manager()->create();

        foreach ([route('panel.agenda'), route('panel.appointments.create'), route('panel.appointments.show', $a), route('panel.appointments.reschedule', $a),
            route('panel.schedule.settings'), route('panel.schedule.working-hours', $this->joao), route('panel.time-off.index'), route('panel.blocks.index')] as $url) {
            $html = $this->actingAs($gerente, 'web')->get($url)->assertOk()->getContent();
            // CSP estrita: nada de estilo ou handler inline.
            $this->assertDoesNotMatchRegularExpression('/sstyle="|son(click|change|submit|load|input)=/i', $html, $url);
        }
    }
}

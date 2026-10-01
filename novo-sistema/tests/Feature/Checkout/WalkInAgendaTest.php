<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Enums\AttendanceSource;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\Team\Enums\TimeOffKind;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * O ENCAIXE OCUPA A AGENDA (correcao da Fase 6): o encaixe e um agendamento
 * de origem "encaixe", reservado pelo mesmo BookingService/Availability da
 * agenda. Mesmas regras de conflito, duracao, profissional, expediente,
 * pausa, bloqueio e folga; nada de segunda regra. Tudo validado no servidor.
 *
 * Relogio: segunda 05/10/2026 (CheckoutFixtures). O encaixe comeca no
 * proximo ponto da grade de 5 min (10:02:20 -> 10:05).
 */
class WalkInAgendaTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    private Professional $maria;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->maria->services()->attach($this->corte->id);
        $this->dono = User::factory()->owner()->create();
    }

    private function as(User $u): static
    {
        return $this->actingAs($u, 'web');
    }

    /** O encaixe tem que ser recusado pela agenda com este motivo, sem gravar nada. */
    private function assertRefused(string $reason, Closure $attempt): void
    {
        $antes = [Appointment::query()->count(), Attendance::query()->count()];
        try {
            $attempt();
            $this->fail("deveria recusar: {$reason}");
        } catch (SlotUnavailable $e) {
            $this->assertSame($reason, $e->result->reasons[0]);
        }
        $this->assertSame($antes, [Appointment::query()->count(), Attendance::query()->count()], 'nada gravado');
    }

    private function onlineAt(string $time, ?Professional $pro = null, ?Service $service = null): Appointment
    {
        return $this->book($this->segunda, $time, $pro, $service, Channel::Customer);
    }

    // --- 1. Horario livre ------------------------------------------------------------------------

    public function test_1_encaixe_em_horario_livre_ocupa_a_agenda(): void
    {
        $this->clockAt('10:02');

        $at = $this->walkIn();

        $ag = $at->appointment;
        $this->assertNotNull($ag);
        $this->assertSame([AttendanceSource::WalkIn, AppointmentSource::WalkIn, $this->joao->id], [$at->source, $ag->source, $ag->professional_id]);
        $this->assertSame(['10:05', '10:35'], [BusinessTime::local($ag->starts_at)->format('H:i'), BusinessTime::local($ag->ends_at)->format('H:i')], 'duração do serviço');
        $this->assertSame(1, $ag->events()->where('type', 'created')->count(), 'histórico da agenda');

        // A MESMA regra de disponibilidade passa a ver o horário ocupado.
        $this->assertSame('conflict', $this->reasonAt($this->segunda, '10:15', channel: Channel::Staff));
        $this->assertNotContains('10:15', $this->freeTimes($this->segunda, channel: Channel::Staff));
        $this->assertContains('10:45', $this->freeTimes($this->segunda, channel: Channel::Staff));
    }

    // --- 2, 3, 4. Conflitos ----------------------------------------------------------------------

    public function test_2_encaixe_sobre_outro_agendamento_e_recusado(): void
    {
        $this->todayAppointment('10:00'); // equipe, 10:00-10:30
        $this->clockAt('10:02');

        $this->assertRefused('conflict', fn () => $this->walkIn());
    }

    public function test_3_agendamento_online_sobre_encaixe_e_recusado(): void
    {
        BookingPolicy::save(['min_notice_minutes' => 0]);
        $this->clockAt('10:02');
        $this->walkIn(); // 10:05-10:35

        foreach (['10:15', '10:30'] as $hora) {
            try {
                $this->onlineAt($hora);
                $this->fail("online às {$hora} deveria ser recusado");
            } catch (SlotUnavailable $e) {
                $this->assertTrue($e->isConflict());
            }
        }
        $this->assertSame(AppointmentSource::Online, $this->onlineAt('10:45')->source, 'logo depois do encaixe pode');
        $this->assertSame(1, Appointment::query()->where('source', 'walk_in')->count());
    }

    public function test_4_encaixe_sobre_agendamento_online_e_recusado(): void
    {
        $this->onlineAt('10:15'); // feito às 08:00, com a antecedência normal
        $this->clockAt('10:02');

        $this->assertRefused('conflict', fn () => $this->walkIn());
    }

    // --- 5. Duracao ------------------------------------------------------------------------------

    public function test_5_respeita_a_duracao_do_servico(): void
    {
        $longo = Service::factory()->create(['name' => 'Corte e barba', 'duration_minutes' => 60, 'price_cents' => 8000]);
        $this->joao->services()->attach($longo->id);
        $this->onlineAt('11:00');
        $this->clockAt('10:02');

        $this->assertRefused('conflict', fn () => $this->walkIn(service: $longo)); // 10:05-11:05 invade as 11:00
        $ag = $this->walkIn()->appointment; // 10:05-10:35 cabe
        $this->assertSame('10:35', BusinessTime::local($ag?->ends_at ?? now())->format('H:i'));

        $this->clockAt('19:42');
        $this->assertRefused('outside_hours', fn () => $this->walkIn($this->maria)); // 19:45-20:15 passa do fechamento
    }

    // --- 6. Profissional -------------------------------------------------------------------------

    public function test_6_respeita_o_profissional(): void
    {
        $this->book($this->segunda, '10:00', $this->maria, channel: Channel::Staff);
        $this->clockAt('10:02');

        $this->assertRefused('conflict', fn () => $this->walkIn($this->maria));
        $doJoao = $this->walkIn($this->joao)->appointment;
        $this->assertSame($this->joao->id, $doJoao?->professional_id, 'agenda de outro profissional não interfere');

        $this->assertRefused('professional_not_qualified', fn () => $this->walkIn($this->maria, $this->barba));
        $this->maria->update(['is_active' => false]);
        $this->assertRefused('professional_unavailable', fn () => $this->walkIn($this->maria));
    }

    // --- 7. Expediente ---------------------------------------------------------------------------

    public function test_7_respeita_o_expediente(): void
    {
        app(ScheduleAdmin::class)->saveWorkingHours($this->maria, [1 => ['13:00', '20:00']], $this->dono);

        $this->clockAt('08:30');
        $this->assertRefused('outside_hours', fn () => $this->attendances()->openWalkIn($this->corte, $this->joao, null, 'Visitante', null, $this->recepcao));

        $this->clockAt('10:02');
        $this->assertRefused('outside_hours', fn () => $this->walkIn($this->maria));

        $this->clockAt('13:02');
        $this->assertSame($this->maria->id, $this->walkIn($this->maria)->professional_id);
    }

    // --- 8. Bloqueios e pausas -------------------------------------------------------------------

    public function test_8_respeita_bloqueios_e_pausas(): void
    {
        $admin = app(ScheduleAdmin::class);
        $admin->addBlock($this->joao, new Interval($this->at($this->segunda, '10:00'), $this->at($this->segunda, '11:00')), 'Reunião', $this->dono);
        $admin->addBlock(null, new Interval($this->at($this->segunda, '15:00'), $this->at($this->segunda, '16:00')), 'Dedetização', $this->dono);
        $admin->addBreak($this->maria, null, '12:00', '13:00', 'Almoço');

        $this->clockAt('10:02');
        $this->assertRefused('blocked', fn () => $this->walkIn($this->joao));

        $this->clockAt('11:47');
        $this->assertRefused('break', fn () => $this->walkIn($this->maria)); // 11:50-12:20 invade a pausa

        $this->clockAt('15:02');
        $this->assertRefused('blocked', fn () => $this->walkIn($this->maria)); // bloqueio da barbearia inteira
    }

    // --- 9. Folgas -------------------------------------------------------------------------------

    public function test_9_respeita_folgas(): void
    {
        app(ScheduleAdmin::class)->addTimeOff($this->joao, $this->segunda, $this->segunda, TimeOffKind::Vacation, 'Folga', $this->dono);
        $this->clockAt('10:02');

        $this->assertRefused('time_off', fn () => $this->walkIn($this->joao));
        $this->assertSame($this->maria->id, $this->walkIn($this->maria)->professional_id);
    }

    // --- Fluxos pelo servidor (HTTP) --------------------------------------------------------------

    public function test_encaixe_criado_cliente_tenta_reservar_o_mesmo_horario_e_o_servidor_recusa(): void
    {
        BookingPolicy::save(['min_notice_minutes' => 0]);
        $this->clockAt('10:02');
        $this->as($this->recepcao)->post(route('panel.attendances.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'contact_name' => 'Visitante Fictício',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // O cliente monta a requisicao a mao (a tela ja nao oferece o horario).
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), [
            'servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15',
        ])->assertRedirect()->assertSessionHasErrors('slot');

        $this->assertSame(0, Appointment::query()->where('customer_id', $this->cliente->id)->count());
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_agendamento_online_criado_recepcao_tenta_encaixe_e_o_servidor_recusa(): void
    {
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), [
            'servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15',
        ])->assertSessionHasNoErrors();
        $this->clockAt('10:02');

        $r = $this->as($this->recepcao)->post(route('panel.attendances.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'contact_name' => 'Visitante Fictício',
        ]);

        $r->assertRedirect()->assertSessionHasErrors('attendance');
        $this->assertStringContainsString('Próximo horário livre hoje: 10:45', (string) session('errors')?->first('attendance'));
        $this->assertSame([1, 0], [Appointment::query()->count(), Attendance::query()->count()]);
    }

    // --- O horario acompanha o atendimento --------------------------------------------------------

    public function test_cancelar_o_encaixe_libera_o_horario(): void
    {
        $this->clockAt('10:02');
        $at = $this->walkIn();

        $this->attendances()->cancel($at, 'Cliente desistiu', $this->recepcao);

        $ag = $at->appointment()->firstOrFail();
        $this->assertSame([AppointmentStatus::Cancelled, 'Encaixe cancelado: Cliente desistiu'], [$ag->status, $ag->cancellation_reason]);
        $this->assertNull($this->reasonAt($this->segunda, '10:15', channel: Channel::Staff), 'horário livre de novo');
    }

    public function test_agenda_nao_libera_o_horario_com_o_cliente_em_atendimento(): void
    {
        $ag = $this->todayAppointment('10:00');
        $this->clockAt('10:12');
        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);
        $this->attendances()->start($at, $this->recepcao);

        foreach ([
            fn () => $this->booking()->cancel($ag, Channel::Staff, $this->recepcao, 'Engano'),
            fn () => $this->booking()->markNoShow($ag, $this->recepcao),
            fn () => $this->booking()->reschedule($ag, $this->at($this->segunda, '15:00'), null, Channel::Staff, $this->recepcao),
        ] as $n => $tentativa) {
            try {
                $tentativa();
                $this->fail("tentativa {$n} deveria ser recusada");
            } catch (BookingRuleViolation $e) {
                $this->assertSame('in_attendance', $e->reason);
            }
        }
        $this->assertSame([AppointmentStatus::Confirmed, '10:00'], [$ag->fresh()?->status, BusinessTime::local($ag->starts_at)->format('H:i')]);

        // Pelo painel tambem (o servidor recusa, nao so a tela).
        $gerente = User::factory()->manager()->create();
        $this->as($gerente)->post(route('panel.appointments.cancel', $ag), ['reason' => 'Engano'])->assertSessionHasErrors('appointment');
        $this->assertSame(AppointmentStatus::Confirmed, $ag->fresh()?->status);
    }

    public function test_trocar_o_profissional_move_a_agenda_ou_e_recusado(): void
    {
        $this->clockAt('10:02');
        $at = $this->walkIn($this->joao); // 10:05-10:35

        $at = $this->attendances()->changeProfessional($at, $this->maria, $this->recepcao);

        $ag = $at->appointment()->firstOrFail();
        $this->assertSame([$this->maria->id, $this->maria->id], [$at->professional_id, $ag->professional_id]);
        $this->assertNull($this->reasonAt($this->segunda, '10:15', $this->joao, channel: Channel::Staff), 'agenda do João liberada');
        $this->assertSame('conflict', $this->reasonAt($this->segunda, '10:15', $this->maria, channel: Channel::Staff), 'agenda da Maria ocupada');

        // Profissional ocupado: recusa e nada muda.
        $pedro = Professional::factory()->create(['display_name' => 'Pedro']);
        $pedro->services()->attach($this->corte->id);
        $this->book($this->segunda, '10:15', $pedro, channel: Channel::Staff);
        try {
            $this->attendances()->changeProfessional($at, $pedro, $this->recepcao);
            $this->fail('Pedro está ocupado');
        } catch (SlotUnavailable $e) {
            $this->assertTrue($e->isConflict());
        }
        $this->assertSame([$this->maria->id, $this->maria->id], [$at->fresh()?->professional_id, $ag->fresh()?->professional_id]);
    }

    public function test_o_mesmo_cliente_nao_fica_em_dois_lugares(): void
    {
        $this->book($this->segunda, '10:15', $this->maria, channel: Channel::Staff, customer: $this->cliente);
        $this->clockAt('10:02');

        $this->expectException(BookingRuleViolation::class);
        $this->walkIn($this->joao, customer: $this->cliente);
    }

    public function test_integridade_encaixe_sempre_na_agenda(): void
    {
        $this->clockAt('10:02');
        $at = $this->walkIn();
        $this->walkIn($this->maria, name: 'Outro Visitante');

        $this->assertArrayHasKey('R33_encaixe_na_agenda', app(IntegrityChecker::class)->rules());
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        // Encaixe "solto" (sem agendamento), gravado por fora das regras: o verificador acusa.
        DB::table('attendances')->where('id', $at->id)->update(['appointment_id' => null, 'active_appointment_id' => null]);
        $this->assertSame(2, app(IntegrityChecker::class)->violations()['R33_encaixe_na_agenda'] ?? 0, 'atendimento sem agendamento e agendamento de encaixe sem atendimento');
    }
}

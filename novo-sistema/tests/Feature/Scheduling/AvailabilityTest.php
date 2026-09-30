<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Team\Enums\TimeOffKind;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * A unica regra de disponibilidade: cada criterio isolado, e a garantia de
 * que a lista de horarios livres e a verificacao de um horario concordam.
 */
class AvailabilityTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    public function test_horario_dentro_do_expediente_esta_livre(): void
    {
        $this->assertNull($this->reasonAt($this->terca, '10:00'));
        $this->assertNull($this->reasonAt($this->terca, '09:00'), 'abertura');
        $this->assertNull($this->reasonAt($this->terca, '19:30'), 'termina exatamente no fechamento');
    }

    public function test_fora_do_expediente_e_atravessando_o_fechamento(): void
    {
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '08:30'));
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '20:00'));
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '19:45'), 'atravessa as 20:00');
    }

    public function test_dia_fechado(): void
    {
        BusinessHour::query()->where('weekday', 2)->delete(); // terca

        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '10:00'));
        $this->assertSame([], $this->freeTimes($this->terca));
    }

    public function test_varios_intervalos_no_mesmo_dia(): void
    {
        BusinessHour::query()->where('weekday', 2)->delete();
        BusinessHour::query()->create(['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00']);
        BusinessHour::query()->create(['weekday' => 2, 'starts_at' => '13:00', 'ends_at' => '18:00']);

        $this->assertNull($this->reasonAt($this->terca, '11:30'));
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '11:45'), 'atravessa o almoco');
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '12:30'));
        $this->assertNull($this->reasonAt($this->terca, '13:00'));
    }

    public function test_intersecao_barbearia_e_expediente_do_profissional(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria']);
        $maria->services()->attach($this->corte->id);
        app(ScheduleAdmin::class)->saveWorkingHours($maria, [2 => ['12:00', '22:00']], User::factory()->owner()->create());

        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '11:30', $maria), 'antes do expediente dela');
        $this->assertNull($this->reasonAt($this->terca, '12:00', $maria));
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '20:00', $maria), 'a barbearia fechou');
        $this->assertSame('outside_hours', $this->reasonAt('2026-10-07', '12:00', $maria), 'quarta: sem expediente');

        $this->assertNull($this->reasonAt($this->terca, '09:00'), 'João sem expediente cadastrado segue a barbearia');
    }

    public function test_pausa_folga_e_bloqueio(): void
    {
        $admin = app(ScheduleAdmin::class);
        $dono = User::factory()->owner()->create();

        $admin->addBreak($this->joao, null, '12:00', '13:00', 'Almoço');
        $this->assertSame('break', $this->reasonAt($this->terca, '12:00'));
        $this->assertSame('break', $this->reasonAt($this->terca, '11:45'), 'termina dentro da pausa');
        $this->assertNull($this->reasonAt($this->terca, '11:30'), 'termina quando a pausa começa');
        $this->assertNull($this->reasonAt($this->terca, '13:00'));

        $admin->addTimeOff($this->joao, '2026-10-07', '2026-10-09', TimeOffKind::Vacation, 'Férias', $dono);
        $this->assertSame('time_off', $this->reasonAt('2026-10-08', '10:00'));
        $this->assertNull($this->reasonAt('2026-10-10', '10:00'));

        $admin->addBlock($this->joao, new Interval($this->at($this->terca, '15:00'), $this->at($this->terca, '16:00')), 'Reunião', $dono);
        $this->assertSame('blocked', $this->reasonAt($this->terca, '15:30'));
        $this->assertSame('blocked', $this->reasonAt($this->terca, '14:45'));
        $this->assertNull($this->reasonAt($this->terca, '16:00'));

        // Bloqueio da barbearia inteira (feriado) vale para todos.
        $admin->addBlock(null, new Interval($this->at('2026-10-12', '00:00'), $this->at('2026-10-13', '00:00')), 'Feriado', $dono);
        $this->assertSame('blocked', $this->reasonAt('2026-10-12', '10:00'));
    }

    public function test_profissional_incompativel_inativo_e_servico_inativo(): void
    {
        $barba = Service::factory()->create(['name' => 'Barba']);
        $this->assertSame('professional_not_qualified', $this->reasonAt($this->terca, '10:00', null, $barba));

        $this->joao->update(['is_bookable' => false]);
        $this->assertSame('professional_unavailable', $this->reasonAt($this->terca, '10:00'));
        $this->joao->update(['is_bookable' => true, 'is_active' => false]);
        $this->assertSame('professional_unavailable', $this->reasonAt($this->terca, '10:00'));
        $this->joao->update(['is_active' => true]);

        $this->corte->update(['is_active' => false]);
        $this->assertSame('service_unavailable', $this->reasonAt($this->terca, '10:00'));
        $this->corte->update(['is_active' => true]);

        $cat = ServiceCategory::factory()->create(['is_active' => false]);
        $this->corte->update(['category_id' => $cat->id]);
        $this->assertSame('service_unavailable', $this->reasonAt($this->terca, '10:00'), 'categoria inativa');
    }

    public function test_passado_e_antecedencia(): void
    {
        // Agora = segunda 08:00. Antecedencia minima do cliente: 120 min.
        $this->assertSame('past', $this->reasonAt($this->segunda, '07:30', null, null, Channel::Staff));
        $this->assertSame('too_soon', $this->reasonAt($this->segunda, '09:30'));
        $this->assertNull($this->reasonAt($this->segunda, '10:00'), 'exatamente 2 h depois');
        $this->assertNull($this->reasonAt($this->segunda, '09:30', null, null, Channel::Staff), 'a equipe encaixa em cima da hora');

        $this->assertSame('too_far', $this->reasonAt('2026-11-10', '10:00'), 'alem de 30 dias');
        $this->assertNull($this->reasonAt('2026-11-10', '10:00', null, null, Channel::Staff));
    }

    public function test_horario_fora_da_grade_de_5_minutos(): void
    {
        $this->assertSame('invalid_time', $this->reasonAt($this->terca, '10:07'));
    }

    public function test_conflito_com_outro_agendamento(): void
    {
        $this->book($this->terca, '10:00');

        $this->assertSame('conflict', $this->reasonAt($this->terca, '10:00'));
        $this->assertSame('conflict', $this->reasonAt($this->terca, '09:45'), 'termina dentro');
        $this->assertSame('conflict', $this->reasonAt($this->terca, '10:15'), 'começa dentro');
        $this->assertNull($this->reasonAt($this->terca, '10:30'), 'adjacente depois');
        $this->assertNull($this->reasonAt($this->terca, '09:30'), 'adjacente antes');

        $longo = Service::factory()->create(['name' => 'Longo', 'duration_minutes' => 90]);
        $this->joao->services()->attach($longo->id);
        $this->assertSame('conflict', $this->reasonAt($this->terca, '09:00', null, $longo), 'envolve o outro');
    }

    public function test_cancelado_libera_o_horario(): void
    {
        $a = $this->book($this->terca, '10:00');
        $this->booking()->cancel($a, Channel::Staff, null);

        $this->assertNull($this->reasonAt($this->terca, '10:00'));
    }

    public function test_lista_de_horarios_e_verificacao_concordam(): void
    {
        $admin = app(ScheduleAdmin::class);
        $admin->addBreak($this->joao, null, '12:00', '13:00', 'Almoço');
        $this->book($this->terca, '10:00');

        $livres = $this->freeTimes($this->terca);

        $this->assertSame('09:00', $livres[0]);
        $this->assertNotContains('10:00', $livres);
        $this->assertNotContains('09:45', $livres);
        $this->assertContains('10:30', $livres);
        $this->assertNotContains('11:45', $livres);
        $this->assertContains('11:30', $livres);
        $this->assertSame('19:30', end($livres));

        // Cada horario da lista passa na verificacao, e cada ponto da grade
        // fora da lista falha nela: uma regra so.
        for ($m = 9 * 60; $m < 20 * 60; $m += 15) {
            $hora = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $this->assertSame(in_array($hora, $livres, true), $this->reasonAt($this->terca, $hora) === null, $hora);
        }
    }

    public function test_servico_longo_e_curto(): void
    {
        $longo = Service::factory()->create(['name' => 'Progressiva', 'duration_minutes' => 180]);
        $curto = Service::factory()->create(['name' => 'Sobrancelha', 'duration_minutes' => 5]);
        $this->joao->services()->attach([$longo->id, $curto->id]);

        $livresLongo = $this->freeTimes($this->terca, null, $longo);
        $this->assertSame('17:00', end($livresLongo), 'último início que termina às 20:00');
        $this->assertSame('outside_hours', $this->reasonAt($this->terca, '17:15', null, $longo));

        $livresCurto = $this->freeTimes($this->terca, null, $curto);
        $this->assertSame('19:45', end($livresCurto), 'grade de 15 min');
    }

    public function test_sem_preferencia_junta_os_profissionais(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria', 'sort_order' => 99]);
        $maria->services()->attach($this->corte->id);
        $this->book($this->terca, '10:00'); // João ocupado as 10:00

        $slots = $this->availability()->slots($this->corte, null, $this->terca, Channel::Customer);
        $dez = collect($slots)->first(fn ($s) => $s['start']->eq($this->at($this->terca, '10:00')));

        $this->assertNotNull($dez, 'Maria ainda está livre às 10:00');
        $this->assertSame(['Maria'], array_map(fn ($p) => $p->display_name, $dez['professionals']));
    }
}

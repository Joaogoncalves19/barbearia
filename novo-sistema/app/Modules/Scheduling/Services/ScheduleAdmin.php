<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Enums\TimeOffKind;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Models\ScheduleBreak;
use App\Modules\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Configuracao de horarios (horarios.md): funcionamento da barbearia,
 * expediente e pausas dos profissionais, folgas e bloqueios. Nada daqui
 * apaga ou move agendamento existente: quem configura ve quantos ficam
 * dentro do periodo e decide (remarcar/cancelar pelo BookingService).
 */
final class ScheduleAdmin
{
    /**
     * Substitui o funcionamento da semana inteira.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week  dia (0-6) => [[inicio, fim], ...]
     */
    public function saveBusinessHours(array $week, User $actor): void
    {
        DB::transaction(function () use ($week, $actor): void {
            foreach ($week as $dia => $intervalos) {
                $this->assertNoOverlap($intervalos);
            }

            BusinessHour::query()->get()->each(fn (BusinessHour $h) => $h->delete());
            foreach ($week as $dia => $intervalos) {
                foreach ($intervalos as [$inicio, $fim]) {
                    BusinessHour::query()->create(['weekday' => $dia, 'starts_at' => $inicio, 'ends_at' => $fim]);
                }
            }

            AuditTrail::record('agenda.business_hours_changed', null, $actor, 'Horário de funcionamento alterado.', [
                'semana' => $this->summary($week),
            ]);
        });
    }

    /**
     * Expediente do profissional. $week nulo = segue o funcionamento da
     * barbearia. Um intervalo por dia (pausas ficam em saveBreaks).
     *
     * @param  array<int, array{0: string, 1: string}>|null  $week  dia => [inicio, fim]; dia ausente = nao trabalha
     */
    public function saveWorkingHours(Professional $professional, ?array $week, User $actor): void
    {
        DB::transaction(function () use ($professional, $week, $actor): void {
            $professional->workingHours()->get()->each->delete();

            foreach ($week ?? [] as $dia => [$inicio, $fim]) {
                $professional->workingHours()->create(['weekday' => $dia, 'starts_at' => $inicio, 'ends_at' => $fim]);
            }

            AuditTrail::record('agenda.working_hours_changed', $professional, $actor, $week === null
                ? 'Expediente: segue o horário da barbearia.'
                : 'Expediente do profissional alterado.', [
                    'semana' => $week === null ? 'barbearia' : $this->summary(array_map(fn ($i) => [$i], $week)),
                ]);
        });
    }

    public function addBreak(Professional $professional, ?int $weekday, string $start, string $end, ?string $label): ScheduleBreak
    {
        return $professional->breaks()->create([
            'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end, 'label' => $label, 'is_active' => true,
        ]);
    }

    public function removeBreak(ScheduleBreak $break): void
    {
        $break->delete();
    }

    public function addTimeOff(Professional $professional, string $startsOn, string $endsOn, TimeOffKind $kind, ?string $reason, User $actor): TimeOff
    {
        return $professional->timeOff()->create([
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'kind' => $kind, 'reason' => $reason,
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function removeTimeOff(TimeOff $timeOff): void
    {
        $timeOff->delete();
    }

    public function addBlock(?Professional $professional, Interval $interval, ?string $reason, User $actor): BlockedSlot
    {
        return BlockedSlot::query()->create([
            'professional_id' => $professional?->id,
            'starts_at' => $interval->start,
            'ends_at' => $interval->end,
            'reason' => $reason,
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function removeBlock(BlockedSlot $block): void
    {
        $block->delete();
    }

    /**
     * Agendamentos que ocupam horario dentro do periodo (para avisar quem
     * cria folga/bloqueio). Nunca sao alterados automaticamente.
     */
    public function appointmentsWithin(?int $professionalId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Appointment::query()
            ->when($professionalId, fn ($q) => $q->where('professional_id', $professionalId))
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, AppointmentStatus::blockingSlot()))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->count();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $intervals
     */
    private function assertNoOverlap(array $intervals): void
    {
        usort($intervals, fn ($a, $b) => strcmp($a[0], $b[0]));
        for ($i = 0; $i < count($intervals); $i++) {
            if ($intervals[$i][1] <= $intervals[$i][0]) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Fechamento deve ser depois da abertura.');
            }
            if ($i > 0 && $intervals[$i][0] < $intervals[$i - 1][1]) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Intervalos do mesmo dia nao podem se sobrepor.');
            }
        }
    }

    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     */
    private function summary(array $week): string
    {
        $dias = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'];
        $partes = [];
        foreach ($week as $d => $intervalos) {
            $partes[] = $dias[$d].' '.implode(' e ', array_map(fn ($i) => $i[0].'-'.$i[1], $intervalos));
        }

        return implode('; ', $partes) ?: 'fechado todos os dias';
    }
}

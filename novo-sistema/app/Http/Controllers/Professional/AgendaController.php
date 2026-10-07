<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agenda do proprio profissional (Fase 12.5), um dia por vez, em linha do
 * tempo: agendamentos com a duracao real (todos os estados, inclusive
 * cancelados e faltas), pausas, bloqueios, folga e o tempo livre.
 *
 * O dia mostrado e sempre o do usuario logado: nao ha parametro de
 * profissional. Tempo livre, pausas e bloqueios vem de
 * Availability::dayOverview, a mesma fotografia do dia que decide a
 * reserva; dia passado nao tem tempo livre, e hoje so a partir de agora.
 */
class AgendaController extends Controller
{
    use ResolvesProfessional;

    public function index(Request $request, Availability $availability): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);
        $hoje = BusinessTime::today();
        $data = (string) $request->query('data', $hoje);
        if (! BusinessTime::isValidDate($data)) {
            $data = $hoje;
        }
        $limites = BusinessTime::dayBounds($data);
        $dia = CarbonImmutable::createFromFormat('Y-m-d', $data, BusinessTime::zone());

        $agendamentos = Appointment::query()->with(['items', 'attendance'])
            ->where('professional_id', $pro->id)
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->orderBy('starts_at')->get();

        $visao = $availability->dayOverview($pro, $data, $data === $hoje ? BusinessTime::now() : null);
        $livres = $data < $hoje ? [] : $visao['free'];
        // Pausa so aparece se cai no expediente do dia (e nao e dia de folga).
        $pausas = $visao['off'] ? [] : array_values(array_filter($visao['breaks'],
            fn (Interval $p) => collect($visao['work'])->contains(fn (Interval $w) => $w->overlaps($p))));

        $bloqueios = BlockedSlot::query()
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', $pro->id))
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->orderBy('starts_at')->get();

        $linhas = $agendamentos->toBase()->map(fn (Appointment $a) => ['kind' => 'appointment', 'start' => $a->starts_at, 'item' => $a])
            ->merge(collect($livres)->map(fn (Interval $i) => ['kind' => 'free', 'start' => $i->start, 'item' => $i]))
            ->merge(collect($pausas)->map(fn (Interval $i) => ['kind' => 'break', 'start' => $i->start, 'item' => $i]))
            ->merge($bloqueios->map(fn (BlockedSlot $b) => ['kind' => 'block', 'start' => $b->starts_at, 'item' => $b]))
            ->sortBy(fn (array $l) => [$l['start']?->getTimestamp(), $l['kind'] === 'appointment' ? 1 : 0])
            ->values();

        // Semana do dia escolhido (segunda a domingo), com quantos horarios ha em cada dia.
        $segunda = $dia->startOfWeek(CarbonImmutable::MONDAY);
        $semana = BusinessTime::dayBounds($segunda->toDateString());
        $fimSemana = BusinessTime::dayBounds($segunda->addDays(6)->toDateString());
        $porDia = Appointment::query()->where('professional_id', $pro->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '>=', $semana->start)->where('starts_at', '<', $fimSemana->end)
            ->get(['starts_at'])
            ->countBy(fn (Appointment $a) => BusinessTime::dateOf($a->starts_at));

        $dias = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $segunda->addDays($i);
            $dias[] = ['date' => $d->toDateString(), 'weekday' => $d->locale('pt_BR')->translatedFormat('D'), 'day' => $d->format('d'), 'count' => (int) ($porDia[$d->toDateString()] ?? 0)];
        }

        $folga = TimeOff::query()->where('professional_id', $pro->id)
            ->whereDate('starts_on', '<=', $data)->whereDate('ends_on', '>=', $data)->first();

        return view('professional.agenda', [
            'professional' => $pro,
            'date' => $data,
            'today' => $hoje,
            'dayLabel' => $dia->locale('pt_BR')->translatedFormat('l, d \d\e F'),
            'prev' => $dia->subDay()->toDateString(),
            'next' => $dia->addDay()->toDateString(),
            'week' => $dias,
            'prevWeek' => $segunda->subWeek()->toDateString(),
            'nextWeek' => $segunda->addWeek()->toDateString(),
            'rows' => $linhas,
            'timeOff' => $folga,
            'works' => $visao['work'] !== [],
            'counts' => [
                'total' => $agendamentos->where('status', '!=', AppointmentStatus::Cancelled)->count(),
                'done' => $agendamentos->where('status', AppointmentStatus::Completed)->count(),
                'noShow' => $agendamentos->where('status', AppointmentStatus::NoShow)->count(),
                'cancelled' => $agendamentos->where('status', AppointmentStatus::Cancelled)->count(),
            ],
            'canBook' => $user->can('createFor', [Appointment::class, $pro]) && $data >= $hoje,
            'canWalkIn' => $data === $hoje && $user->can('openFor', [Attendance::class, $pro]),
        ]);
    }
}

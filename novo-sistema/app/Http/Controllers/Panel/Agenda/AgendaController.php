<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Agenda do dia, por profissional (leitura rapida: hora, duracao, cliente,
 * servico, status). Quem tem appointments.view_all ve todos (e pode filtrar);
 * quem tem so view_own (profissional) ve SO a propria agenda, qualquer que
 * seja o filtro enviado na URL.
 */
class AgendaController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user('web');
        $data = (string) $request->query('data', BusinessTime::today());
        if (! BusinessTime::isValidDate($data)) {
            $data = BusinessTime::today();
        }
        $cancelados = $request->boolean('cancelados');

        $profissionais = $this->visibleProfessionals($user, $request->query('profissional'));
        $limites = BusinessTime::dayBounds($data);

        $agendamentos = Appointment::query()
            ->whereIn('professional_id', $profissionais->pluck('id'))
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->when(! $cancelados, fn ($q) => $q->where('status', '!=', AppointmentStatus::Cancelled->value))
            ->with('items')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('professional_id');

        $bloqueios = BlockedSlot::query()
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhereIn('professional_id', $profissionais->pluck('id')))
            ->where('starts_at', '<', $limites->end)->where('ends_at', '>', $limites->start)
            ->orderBy('starts_at')->get();

        $dia = CarbonImmutable::createFromFormat('Y-m-d', $data, BusinessTime::zone());

        return view('panel.agenda.index', [
            'date' => $data,
            'dayLabel' => $dia->locale('pt_BR')->translatedFormat('l, d \d\e F'),
            'prev' => $dia->subDay()->toDateString(),
            'next' => $dia->addDay()->toDateString(),
            'today' => BusinessTime::today(),
            'professionals' => $profissionais,
            'appointments' => $agendamentos,
            'blocks' => $bloqueios,
            'offToday' => Professional::query()->whereIn('id', $profissionais->pluck('id'))
                ->whereHas('timeOff', fn ($q) => $q->whereDate('starts_on', '<=', $data)->whereDate('ends_on', '>=', $data))
                ->pluck('id')->all(),
            'filter' => $request->query('profissional'),
            'allProfessionals' => $user->can('appointments.view_all') ? Professional::query()->where('is_active', true)->ordered()->get() : collect(),
            'showCancelled' => $cancelados,
        ]);
    }

    /**
     * @return Collection<int, Professional>
     */
    private function visibleProfessionals(User $user, mixed $filter): Collection
    {
        if (! $user->can('appointments.view_all')) {
            // Profissional: so a propria agenda, ignorando qualquer filtro.
            return $user->professional !== null ? collect([$user->professional]) : collect();
        }

        return Professional::query()
            ->where('is_active', true)
            ->when(is_string($filter) && ctype_digit($filter), fn ($q) => $q->whereKey((int) $filter))
            ->ordered()
            ->get();
    }
}

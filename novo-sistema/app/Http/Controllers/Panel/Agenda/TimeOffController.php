<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Enums\TimeOffKind;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Models\TimeOff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Folgas de dias inteiros (schedule.time_off). Nao mexe em agendamento: a
 * tela avisa quantos ficam dentro do periodo para a equipe remarcar.
 */
class TimeOffController extends Controller
{
    public function index(): View
    {
        return view('panel.agenda.time-off', [
            'items' => TimeOff::query()->with('professional')->whereDate('ends_on', '>=', BusinessTime::today())->orderBy('starts_on')->get(),
            'past' => TimeOff::query()->with('professional')->whereDate('ends_on', '<', BusinessTime::today())->orderByDesc('starts_on')->limit(20)->get(),
            'professionals' => Professional::query()->where('is_active', true)->ordered()->get(),
            'kinds' => collect(TimeOffKind::cases())->mapWithKeys(fn (TimeOffKind $k) => [$k->value => $k->label()])->all(),
        ]);
    }

    public function store(Request $request, ScheduleAdmin $admin): RedirectResponse
    {
        $dados = $request->validate([
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'kind' => ['required', new Enum(TimeOffKind::class)],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [], ['professional_id' => 'profissional', 'starts_on' => 'início', 'ends_on' => 'fim', 'kind' => 'tipo', 'reason' => 'motivo']);

        if ($dados['ends_on'] < BusinessTime::today()) {
            throw ValidationException::withMessages(['ends_on' => 'A folga precisa terminar hoje ou depois.']);
        }

        $pro = Professional::query()->findOrFail($dados['professional_id']);
        /** @var User $user */
        $user = $request->user('web');
        $admin->addTimeOff($pro, $dados['starts_on'], $dados['ends_on'], TimeOffKind::from($dados['kind']), $dados['reason'] ?? null, $user);

        $afetados = $admin->appointmentsWithin($pro->id, BusinessTime::dayBounds($dados['starts_on'])->start, BusinessTime::dayBounds($dados['ends_on'])->end);

        return back()->with('status', 'Folga registrada.'.($afetados > 0
            ? " Atenção: {$pro->display_name} tem {$afetados} agendamento(s) nesse período. Eles não foram alterados: remarque ou cancele pela agenda."
            : ''));
    }

    public function destroy(TimeOff $timeOff, ScheduleAdmin $admin): RedirectResponse
    {
        // Folga que ja passou fica como historico.
        abort_if($timeOff->ends_on->toDateString() < BusinessTime::today(), 403);
        $admin->removeTimeOff($timeOff);

        return back()->with('status', 'Folga removida.');
    }
}

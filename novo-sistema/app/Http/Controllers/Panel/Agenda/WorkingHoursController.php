<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Models\ScheduleBreak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Expediente e pausas de um profissional (schedule.working_hours). Um
 * intervalo por dia; pausas (almoco) a parte. "Segue a barbearia" = sem
 * expediente proprio.
 */
class WorkingHoursController extends Controller
{
    public function edit(Professional $professional): View
    {
        return view('panel.agenda.working-hours', [
            'professional' => $professional,
            'weekdays' => ScheduleSettingsController::WEEKDAYS,
            'hours' => $professional->workingHours()->get()->keyBy('weekday'),
            'followsShop' => ! $professional->workingHours()->exists(),
            'breaks' => $professional->breaks()->orderBy('weekday')->orderBy('starts_at')->get(),
        ]);
    }

    public function update(Request $request, Professional $professional, ScheduleAdmin $admin): RedirectResponse
    {
        $request->validate(['mode' => ['required', 'in:shop,own'], 'days' => ['nullable', 'array']]);

        $semana = null;
        if ($request->input('mode') === 'own') {
            $semana = [];
            $erros = [];
            for ($d = 0; $d <= 6; $d++) {
                if (! $request->boolean("days.{$d}.works")) {
                    continue;
                }
                $inicio = (string) $request->input("days.{$d}.start");
                $fim = (string) $request->input("days.{$d}.end");
                if (! BusinessTime::isValidTime($inicio) || ! BusinessTime::isValidTime($fim) || $fim <= $inicio) {
                    $erros["days.{$d}.start"] = ScheduleSettingsController::WEEKDAYS[$d].': informe início e fim válidos.';

                    continue;
                }
                $semana[$d] = [$inicio, $fim];
            }
            if ($erros !== []) {
                throw ValidationException::withMessages($erros);
            }
        }

        $admin->saveWorkingHours($professional, $semana, $this->user($request));

        return back()->with('status', 'Expediente salvo. Agendamentos já feitos não mudam.');
    }

    public function storeBreak(Request $request, Professional $professional, ScheduleAdmin $admin): RedirectResponse
    {
        $dados = $request->validate([
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'start' => ['required', 'string'],
            'end' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:60'],
        ], [], ['start' => 'início', 'end' => 'fim']);

        if (! BusinessTime::isValidTime($dados['start']) || ! BusinessTime::isValidTime($dados['end']) || $dados['end'] <= $dados['start']) {
            throw ValidationException::withMessages(['start' => 'Informe início e fim válidos (o fim depois do início).']);
        }

        $admin->addBreak($professional, isset($dados['weekday']) ? (int) $dados['weekday'] : null, $dados['start'], $dados['end'], $dados['label'] ?? null);

        return back()->with('status', 'Pausa adicionada.');
    }

    public function destroyBreak(Professional $professional, ScheduleBreak $break, ScheduleAdmin $admin): RedirectResponse
    {
        abort_unless($break->professional_id === $professional->id, 404);
        $admin->removeBreak($break);

        return back()->with('status', 'Pausa removida.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

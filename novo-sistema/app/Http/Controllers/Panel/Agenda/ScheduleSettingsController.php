<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Horario de funcionamento da barbearia e regras da agenda
 * (schedule.settings). Ate dois intervalos por dia (ex.: fecha no almoco).
 */
class ScheduleSettingsController extends Controller
{
    public const WEEKDAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    public function edit(): View
    {
        $horas = BusinessHour::query()->orderBy('weekday')->orderBy('starts_at')->get()->groupBy('weekday');

        return view('panel.agenda.settings', [
            'weekdays' => self::WEEKDAYS,
            'hours' => $horas,
            'policy' => BookingPolicy::current()->toArray(),
            'fields' => BookingPolicy::FIELDS,
            'zone' => BusinessTime::zone(),
        ]);
    }

    public function update(Request $request, ScheduleAdmin $admin): RedirectResponse
    {
        $request->validate([
            'hours' => ['nullable', 'array'],
            'hours.*.*.start' => ['nullable', 'string'],
            'hours.*.*.end' => ['nullable', 'string'],
            'policy' => ['required', 'array'],
        ]);

        $semana = [];
        $erros = [];
        for ($d = 0; $d <= 6; $d++) {
            foreach ((array) $request->input("hours.{$d}", []) as $i => $par) {
                $inicio = trim((string) ($par['start'] ?? ''));
                $fim = trim((string) ($par['end'] ?? ''));
                if ($inicio === '' && $fim === '') {
                    continue;
                }
                if (! BusinessTime::isValidTime($inicio) || ! BusinessTime::isValidTime($fim) || $fim <= $inicio) {
                    $erros["hours.{$d}.{$i}.start"] = self::WEEKDAYS[$d].': informe abertura e fechamento válidos (o fechamento depois da abertura).';

                    continue;
                }
                $semana[$d][] = [$inicio, $fim];
            }
        }

        $politica = [];
        foreach (BookingPolicy::FIELDS as $campo => $def) {
            $valor = $request->input("policy.{$campo}");
            if (is_bool($def['default'])) {
                $politica[$campo] = (bool) $valor;
            } elseif (! is_numeric($valor) || (int) $valor < $def['min'] || (int) $valor > $def['max'] || ($campo === 'slot_step_minutes' && (int) $valor % 5 !== 0)) {
                $erros["policy.{$campo}"] = "Valor entre {$def['min']} e {$def['max']}".($campo === 'slot_step_minutes' ? ', múltiplo de 5' : '').'.';
            } else {
                $politica[$campo] = (int) $valor;
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        /** @var User $user */
        $user = $request->user('web');
        try {
            $admin->saveBusinessHours($semana, $user);
        } catch (DomainRuleViolation) {
            throw ValidationException::withMessages(['hours' => 'Os intervalos de um mesmo dia não podem se sobrepor.']);
        }
        BookingPolicy::save($politica);

        return redirect()->route('panel.schedule.settings')->with('status', 'Horário de funcionamento e regras da agenda salvos. Agendamentos já feitos não mudam.');
    }
}

<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Services\ScheduleAdmin;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bloqueios de agenda (schedule.blocks): de um profissional ou da barbearia
 * inteira. Nao mexe em agendamento existente (a tela avisa quantos ha).
 */
class BlockController extends Controller
{
    public function index(): View
    {
        $agora = BusinessTime::now();

        return view('panel.agenda.blocks', [
            'items' => BlockedSlot::query()->with(['professional', 'createdBy'])->where('ends_at', '>', $agora)->orderBy('starts_at')->get(),
            'past' => BlockedSlot::query()->with('professional')->where('ends_at', '<=', $agora)->orderByDesc('starts_at')->limit(20)->get(),
            'professionals' => Professional::query()->where('is_active', true)->ordered()->get(),
            'today' => BusinessTime::today(),
        ]);
    }

    public function store(Request $request, ScheduleAdmin $admin): RedirectResponse
    {
        $dados = $request->validate([
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'start' => ['nullable', 'string'],
            'end' => ['nullable', 'string'],
            'all_day' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:120'],
        ], [], ['date' => 'data', 'end_date' => 'data final', 'start' => 'início', 'end' => 'fim', 'reason' => 'motivo']);

        $diaFinal = $dados['end_date'] ?? $dados['date'];
        if ($request->boolean('all_day')) {
            $intervalo = new Interval(BusinessTime::dayBounds($dados['date'])->start, BusinessTime::dayBounds($diaFinal)->end);
        } else {
            $inicio = (string) ($dados['start'] ?? '');
            $fim = (string) ($dados['end'] ?? '');
            if (! BusinessTime::isValidTime($inicio) || ! BusinessTime::isValidTime($fim)) {
                throw ValidationException::withMessages(['start' => 'Informe início e fim, ou marque "dia inteiro".']);
            }
            $de = BusinessTime::at($dados['date'], $inicio);
            $ate = BusinessTime::at($diaFinal, $fim);
            if ($ate->lte($de)) {
                throw ValidationException::withMessages(['end' => 'O fim precisa ser depois do início.']);
            }
            $intervalo = new Interval($de, $ate);
        }

        if ($intervalo->end->lte(BusinessTime::now())) {
            throw ValidationException::withMessages(['date' => 'O bloqueio precisa terminar no futuro.']);
        }

        $pro = isset($dados['professional_id']) ? Professional::query()->find($dados['professional_id']) : null;
        /** @var User $user */
        $user = $request->user('web');
        $admin->addBlock($pro, $intervalo, $dados['reason'], $user);

        $afetados = $admin->appointmentsWithin($pro?->id, $intervalo->start, $intervalo->end);

        return back()->with('status', 'Bloqueio criado'.($pro ? " na agenda de {$pro->display_name}" : ' para a barbearia inteira').'.'.($afetados > 0
            ? " Atenção: há {$afetados} agendamento(s) nesse período. Eles não foram alterados: remarque ou cancele pela agenda."
            : ''));
    }

    public function destroy(BlockedSlot $block, ScheduleAdmin $admin): RedirectResponse
    {
        // Bloqueio que ja terminou fica como historico.
        abort_if($block->ends_at->lte(BusinessTime::now()), 403);
        $admin->removeBlock($block);

        return back()->with('status', 'Bloqueio removido.');
    }
}

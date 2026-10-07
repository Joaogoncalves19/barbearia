<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Interval;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Hoje" do profissional (Fase 12.5): quem esta na cadeira, quem e o
 * proximo, o resto do dia (com o tempo livre) e quanto ja produziu.
 *
 * So leitura e so do proprio profissional. Tempo livre: Availability
 * (mesma fotografia do dia que decide a reserva). Producao: os lancamentos
 * de comissao e gorjeta da Fase 7 e os totais congelados dos atendimentos
 * concluidos; nada e recalculado.
 */
class TodayController extends Controller
{
    use ResolvesProfessional;

    public function __invoke(Request $request, Availability $availability, ProfessionalLedger $ledger): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);
        $agora = BusinessTime::now();
        $hoje = BusinessTime::today();
        $dia = BusinessTime::dayBounds($hoje);

        $doDia = Appointment::query()->with(['items', 'attendance'])
            ->where('professional_id', $pro->id)
            ->where('starts_at', '<', $dia->end)->where('ends_at', '>', $dia->start)
            ->orderBy('starts_at')->get();

        // Na cadeira: em andamento primeiro, depois os abertos (cliente chegou).
        $naCadeira = Attendance::query()->with(['items', 'appointment', 'professional'])
            ->where('professional_id', $pro->id)
            ->whereIn('status', [AttendanceStatus::InProgress->value, AttendanceStatus::Open->value])
            ->orderByRaw('case when status = ? then 0 else 1 end', [AttendanceStatus::InProgress->value])
            ->orderBy('opened_at')->get();

        $abertos = [AppointmentStatus::Pending, AppointmentStatus::AwaitingPayment, AppointmentStatus::Confirmed];
        $aSeguir = $doDia->filter(fn (Appointment $a) => in_array($a->status, $abertos, true)
            && $a->ends_at !== null && $a->ends_at->gt($agora)
            && ($a->attendance === null || ! $a->attendance->status->isEditable()))->values();

        $livres = $availability->dayOverview($pro, $hoje, $agora)['free'];
        $resto = collect($aSeguir->slice(1))->map(fn (Appointment $a) => ['kind' => 'appointment', 'start' => $a->starts_at, 'item' => $a])
            ->merge(collect($livres)->map(fn (Interval $i) => ['kind' => 'free', 'start' => $i->start, 'item' => $i]))
            ->sortBy(fn (array $l) => $l['start']?->getTimestamp())->values();

        $concluidos = Attendance::query()->where('professional_id', $pro->id)
            ->where('status', AttendanceStatus::Completed->value)
            ->where('completed_at', '>=', $dia->start)->where('completed_at', '<', $dia->end);

        return view('professional.today', [
            'user' => $user,
            'professional' => $pro,
            'greeting' => match (true) {
                (int) BusinessTime::local($agora)->format('H') < 12 => 'Bom dia',
                (int) BusinessTime::local($agora)->format('H') < 18 => 'Boa tarde',
                default => 'Boa noite',
            },
            'now' => $agora,
            'inChair' => $naCadeira,
            'next' => $aSeguir->first(),
            'rest' => $resto,
            'counts' => [
                'total' => $doDia->where('status', '!=', AppointmentStatus::Cancelled)->count(),
                'done' => $doDia->where('status', AppointmentStatus::Completed)->count(),
                'left' => $aSeguir->count(),
                'noShow' => $doDia->where('status', AppointmentStatus::NoShow)->count(),
            ],
            'earned' => $ledger->earnedBetween($pro, $dia->start, $dia->end),
            'completed' => ['count' => (clone $concluidos)->count(), 'total' => (int) (clone $concluidos)->sum('total_cents')],
            'canBook' => $user->can('createFor', [Appointment::class, $pro]),
            'canWalkIn' => $user->can('openFor', [Attendance::class, $pro]),
        ]);
    }
}

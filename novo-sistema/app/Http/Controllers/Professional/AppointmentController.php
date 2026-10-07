<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Services\CustomerNotes;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Um agendamento visto pelo profissional (Fase 12.5): o que ele precisa para
 * atender. A rota exige can:view (so a propria agenda; outro = 404).
 *
 * Do cliente, so o necessario: nome, telefone, observacoes, as anotacoes
 * dos profissionais (customers.notes_own) e os atendimentos anteriores COM
 * ESTE profissional (P12.5-06). Nada de e-mail, CPF ou nascimento.
 * As acoes (abrir atendimento, confirmar, falta, remarcar, cancelar) usam
 * as rotas e regras do painel.
 */
class AppointmentController extends Controller
{
    use ResolvesProfessional;

    public function show(Request $request, Appointment $appointment, CustomerNotes $notes): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);
        $appointment->load(['items', 'attendance.professional', 'customer', 'events', 'professional']);
        $cliente = $appointment->customer;
        $atual = $appointment->attendance;

        $anteriores = $cliente === null ? collect() : Attendance::query()->with(['items', 'consumptions'])
            ->where('customer_id', $cliente->id)
            ->where('professional_id', $pro->id)
            ->where('status', AttendanceStatus::Completed->value)
            ->when($atual !== null, fn ($q) => $q->whereKeyNot($atual->id))
            ->latest('completed_at')->limit(5)->get();

        $podeAnotar = $cliente !== null && $user->can('notes', $cliente);

        return view('professional.appointment', [
            'appointment' => $appointment,
            'customer' => $cliente,
            'history' => $anteriores,
            'canNote' => $podeAnotar,
            'notes' => $podeAnotar ? $notes->forProfessionals($cliente) : collect(),
            'noteMax' => CustomerNotes::MAX_LENGTH,
            'canOpen' => $user->can('openFor', [Attendance::class, $pro]),
        ]);
    }
}

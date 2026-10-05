<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmacao de presenca pelo link do lembrete (D-51; lembretes.md §4).
 *
 * - Link ASSINADO e com validade ate o horario do agendamento (middleware
 *   "signed"): ninguem confirma o agendamento de outra pessoa trocando o
 *   codigo, e o link do horario antigo deixa de valer quando passa.
 * - Abrir o link (GET) so MOSTRA; confirmar e um POST (leitores de e-mail
 *   e antivirus abrem links sozinhos).
 * - Sem login e sem dado pessoal na tela alem do primeiro nome.
 */
class PresenceController extends Controller
{
    public function show(Appointment $appointment): View
    {
        return view('site.presence', ['appointment' => $appointment, 'open' => $this->isOpen($appointment)]);
    }

    public function store(Request $request, Appointment $appointment, BookingService $booking): RedirectResponse
    {
        try {
            $agora = $booking->confirmPresence($appointment);
        } catch (BookingRuleViolation) {
            return redirect()->to($request->fullUrl())->with('error', 'Este agendamento não está mais ativo. Fale com a barbearia.');
        }

        return redirect()->to($request->fullUrl())->with('status', $agora ? 'Presença confirmada. Até lá!' : 'Sua presença já estava confirmada.');
    }

    private function isOpen(Appointment $a): bool
    {
        return in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true) && $a->starts_at !== null && $a->starts_at->isFuture();
    }
}

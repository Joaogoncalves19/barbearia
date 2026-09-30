<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agendamentos do proprio cliente. Rotas: can:view/cancel/reschedule
 * (AppointmentPolicy: so os proprios; alheio = 404). Prazos e limites: no
 * BookingService (canal Cliente), com a mensagem certa para cada caso.
 */
class AppointmentController extends Controller
{
    public function show(Appointment $appointment): View
    {
        $appointment->load('items');

        return view('account.appointment', [
            'appointment' => $appointment,
            'policy' => BookingPolicy::current(),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment, BookingService $booking): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:64']]);

        try {
            $booking->cancel($appointment, Channel::Customer, $this->customer($request), $request->string('reason')->value() ?: 'Cancelado pelo cliente');
        } catch (BookingRuleViolation $e) {
            return back()->withErrors(['appointment' => $e->getMessage()]);
        }

        return redirect()->route('account.appointments.show', $appointment)->with('status', 'Agendamento cancelado.');
    }

    public function editReschedule(Request $request, Appointment $appointment, Availability $availability, ProfessionalDirectory $directory): View
    {
        $servico = $this->serviceOf($appointment);
        $duracao = $this->minutesOf($appointment);
        $pro = $this->targetProfessional($request, $appointment, $servico, $directory);

        $dias = $availability->bookableDates(Channel::Customer);
        $data = (string) $request->query('data', BusinessTime::dateOf($appointment->starts_at ?? BusinessTime::now()));
        if (! in_array($data, $dias, true)) {
            $data = $dias[0] ?? BusinessTime::today();
        }

        return view('account.reschedule', [
            'appointment' => $appointment,
            'service' => $servico,
            'professional' => $pro,
            'professionals' => $servico !== null ? $directory->bookableFor($servico) : collect(),
            'days' => $dias,
            'date' => $data,
            'slots' => $servico !== null && $pro !== null ? $availability->slots($servico, $pro, $data, Channel::Customer, $appointment, $duracao) : [],
        ]);
    }

    public function reschedule(Request $request, Appointment $appointment, BookingService $booking, ProfessionalDirectory $directory): RedirectResponse
    {
        $dados = $request->validate([
            'data' => ['required', 'string', 'size:10'],
            'hora' => ['required', 'string', 'size:5'],
            'profissional' => ['required', 'string', 'max:80'],
        ]);
        abort_unless(BusinessTime::isValidDate($dados['data']) && BusinessTime::isValidTime($dados['hora']), 404);

        $servico = $this->serviceOf($appointment);
        abort_if($servico === null, 404);
        $pro = $directory->bookableFor($servico)->first(fn (Professional $p) => $p->slug === $dados['profissional']);
        abort_if($pro === null, 404);

        try {
            $booking->reschedule($appointment, BusinessTime::at($dados['data'], $dados['hora']), $pro, Channel::Customer, $this->customer($request));
        } catch (SlotUnavailable|BookingRuleViolation $e) {
            return redirect()->route('account.appointments.reschedule', ['appointment' => $appointment, 'data' => $dados['data'], 'profissional' => $pro->slug])
                ->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->route('account.appointments.show', $appointment)->with('status', 'Agendamento remarcado.');
    }

    private function serviceOf(Appointment $appointment): ?Service
    {
        $id = $appointment->items()->whereNotNull('service_id')->value('service_id');

        return $id !== null ? Service::withTrashed()->find($id) : null;
    }

    private function minutesOf(Appointment $appointment): int
    {
        return (int) $appointment->starts_at?->diffInMinutes($appointment->ends_at);
    }

    /** Profissional escolhido na tela (?profissional=slug) ou o atual, se ainda atende. */
    private function targetProfessional(Request $request, Appointment $appointment, ?Service $service, ProfessionalDirectory $directory): ?Professional
    {
        if ($service === null) {
            return null;
        }
        $possiveis = $directory->bookableFor($service);
        $slug = $request->query('profissional');

        return $slug !== null
            ? $possiveis->first(fn (Professional $p) => $p->slug === $slug)
            : ($possiveis->first(fn (Professional $p) => $p->id === $appointment->professional_id) ?? $possiveis->first());
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}

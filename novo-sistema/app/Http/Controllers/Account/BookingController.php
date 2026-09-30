<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmacao do agendamento pelo cliente logado (ultima etapa do fluxo do
 * site). A escolha (servico, profissional, dia, hora) chega pela URL e e
 * REVALIDADA aqui e no BookingService: manipular a URL nunca reserva algo
 * que a regra nao aceita.
 */
class BookingController extends Controller
{
    public function confirm(Request $request, ProfessionalDirectory $directory, Availability $availability): View|RedirectResponse
    {
        [$servico, $pro, $inicio] = $this->choice($request, $directory);

        $livre = $pro !== null
            ? $availability->check($servico, $pro, $inicio, Channel::Customer)
            : null;
        $semPreferencia = $pro === null
            ? collect($availability->slots($servico, null, BusinessTime::dateOf($inicio), Channel::Customer))->first(fn ($s) => $s['start']->eq($inicio))
            : null;

        if (($livre !== null && ! $livre->isAvailable()) || ($pro === null && $semPreferencia === null)) {
            return $this->backToSlots($servico, $pro, $inicio, $livre?->message() ?? 'Este horário acabou de ser ocupado. Escolha outro.');
        }

        return view('account.booking-confirm', [
            'service' => $servico,
            'professional' => $pro ?? $semPreferencia['professionals'][0],
            'anyProfessional' => $pro === null,
            'start' => $inicio,
            'end' => $inicio->addMinutes($servico->duration_minutes),
            'query' => $request->only(['servico', 'profissional', 'data', 'hora']),
        ]);
    }

    public function store(Request $request, ProfessionalDirectory $directory, BookingService $booking): RedirectResponse
    {
        [$servico, $pro, $inicio] = $this->choice($request, $directory);

        /** @var Customer $cliente */
        $cliente = $request->user('customer');

        try {
            $a = $booking->book(new BookingRequest(
                service: $servico,
                professional: $pro,
                start: $inicio,
                channel: Channel::Customer,
                source: AppointmentSource::Online,
                customer: $cliente,
                notes: $request->filled('notes') ? mb_substr(trim($request->string('notes')->value()), 0, 500) : null,
                actor: $cliente,
            ));
        } catch (SlotUnavailable $e) {
            return $this->backToSlots($servico, $pro, $inicio, $e->getMessage());
        } catch (BookingRuleViolation $e) {
            return $this->backToSlots($servico, $pro, $inicio, $e->getMessage());
        }

        return redirect()->route('account.appointments.show', $a)->with('status', 'Agendamento feito! Código '.$a->code.'.');
    }

    /**
     * Le e valida a escolha. Qualquer valor invalido = 404 (nao ha o que
     * mostrar), nunca uma reserva "aproximada".
     *
     * @return array{0: Service, 1: ?Professional, 2: CarbonImmutable}
     */
    private function choice(Request $request, ProfessionalDirectory $directory): array
    {
        $dados = $request->validate([
            'servico' => ['required', 'string', 'max:80'],
            'profissional' => ['required', 'string', 'max:80'],
            'data' => ['required', 'string', 'size:10'],
            'hora' => ['required', 'string', 'size:5'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(BusinessTime::isValidDate($dados['data']) && BusinessTime::isValidTime($dados['hora']), 404);

        $servico = Service::query()->bookable()->where('slug', $dados['servico'])->first();
        abort_if($servico === null, 404);

        $pro = null;
        if ($dados['profissional'] !== 'qualquer') {
            $pro = $directory->bookableFor($servico)->first(fn (Professional $p) => $p->slug === $dados['profissional']);
            abort_if($pro === null, 404);
        }

        return [$servico, $pro, BusinessTime::at($dados['data'], $dados['hora'])];
    }

    private function backToSlots(Service $service, ?Professional $pro, CarbonImmutable $start, string $message): RedirectResponse
    {
        return redirect()->route('booking.slots', [
            'service' => $service->slug,
            'profissional' => $pro !== null ? $pro->slug : 'qualquer',
            'data' => BusinessTime::dateOf($start),
        ])->withErrors(['slot' => $message]);
    }
}

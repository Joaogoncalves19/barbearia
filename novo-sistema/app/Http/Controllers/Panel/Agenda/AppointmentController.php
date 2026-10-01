<?php

namespace App\Http\Controllers\Panel\Agenda;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLookup;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Agendamentos pela equipe. Tudo passa pelo BookingService (canal Equipe);
 * aqui so se le a intencao e se mostra o resultado.
 *
 * Autorizacao: rota (can:agenda.view + policy do registro) e, ao criar,
 * AppointmentPolicy@createFor no profissional escolhido (quem so tem
 * manage_own cria so na propria agenda).
 */
class AppointmentController extends Controller
{
    public function __construct(private readonly BookingService $booking) {}

    public function create(Request $request, ServiceCatalog $catalog, ProfessionalDirectory $directory, Availability $availability): View
    {
        $user = $this->user($request);
        abort_unless($user->can('appointments.manage') || $user->can('appointments.manage_own'), 403);

        $servico = $request->filled('servico') ? Service::query()->bookable()->find($request->integer('servico')) : null;
        $opcoes = $servico !== null ? $this->allowedProfessionals($user, $directory->bookableFor($servico)) : collect();
        $pro = $request->filled('profissional') ? $opcoes->firstWhere('id', $request->integer('profissional')) : null;

        $dias = $availability->bookableDates(Channel::Staff);
        $data = (string) $request->query('data', $dias[0] ?? BusinessTime::today());
        if (! in_array($data, $dias, true)) {
            $data = $dias[0] ?? BusinessTime::today();
        }

        $termo = trim((string) $request->query('cliente', ''));

        return view('panel.agenda.create', [
            'groups' => $catalog->bookableByCategory(),
            'service' => $servico,
            'professionals' => $opcoes,
            'professional' => $pro,
            'days' => $dias,
            'date' => $data,
            'slots' => $servico !== null && $pro !== null ? $availability->slots($servico, $pro, $data, Channel::Staff) : [],
            'term' => $termo,
            'customers' => app(CustomerLookup::class)->search($user, $termo),
            'selectedTime' => (string) $request->query('hora', ''),
        ]);
    }

    public function store(Request $request, ProfessionalDirectory $directory): RedirectResponse
    {
        $user = $this->user($request);
        $dados = $request->validate([
            'service_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'data' => ['required', 'string', 'size:10'],
            'hora' => ['required', 'string', 'size:5'],
            'customer_id' => ['nullable', 'integer'],
            'contact_name' => ['nullable', 'required_without:customer_id', 'string', 'min:2', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:25'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['contact_name.required_without' => 'Escolha um cliente cadastrado ou informe o nome de quem vai ser atendido.'],
            ['contact_name' => 'nome do cliente', 'contact_phone' => 'telefone']);

        abort_unless(BusinessTime::isValidDate($dados['data']) && BusinessTime::isValidTime($dados['hora']), 422);

        $servico = Service::query()->findOrFail($dados['service_id']);
        $pro = Professional::query()->findOrFail($dados['professional_id']);
        Gate::forUser($user)->authorize('createFor', [Appointment::class, $pro]);

        $cliente = null;
        if (! empty($dados['customer_id'])) {
            abort_unless($user->can('customers.view') || $user->can('customers.view_own'), 403);
            $cliente = Customer::query()->findOrFail($dados['customer_id']);
            Gate::forUser($user)->authorize('view', $cliente);
        }

        $voltar = fn (string $msg) => redirect()->route('panel.appointments.create', [
            'servico' => $servico->id, 'profissional' => $pro->id, 'data' => $dados['data'],
        ])->withInput()->withErrors(['slot' => $msg]);

        try {
            $a = $this->booking->book(new BookingRequest(
                service: $servico,
                professional: $pro,
                start: BusinessTime::at($dados['data'], $dados['hora']),
                channel: Channel::Staff,
                source: AppointmentSource::Staff,
                customer: $cliente,
                contactName: $cliente === null ? $dados['contact_name'] : null,
                contactPhone: $cliente === null ? ($dados['contact_phone'] ?? null) : null,
                notes: $dados['notes'] ?? null,
                actor: $user,
            ));
        } catch (SlotUnavailable|BookingRuleViolation $e) {
            return $voltar($e->getMessage());
        }

        return redirect()->route('panel.appointments.show', $a)->with('status', "Agendamento {$a->code} criado.");
    }

    public function show(Appointment $appointment): View
    {
        $appointment->load(['items', 'events' => fn ($q) => $q->orderBy('id'), 'customer', 'attendance', 'professional']);

        return view('panel.agenda.show', ['appointment' => $appointment]);
    }

    public function updateNotes(Request $request, Appointment $appointment): RedirectResponse
    {
        $dados = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $this->booking->updateNotes($appointment, $dados['notes'] ?? null, $this->user($request));

        return back()->with('status', 'Observações salvas.');
    }

    public function confirm(Request $request, Appointment $appointment): RedirectResponse
    {
        return $this->run(fn () => $this->booking->confirm($appointment, $this->user($request)), 'Agendamento confirmado.');
    }

    public function noShow(Request $request, Appointment $appointment): RedirectResponse
    {
        return $this->run(fn () => $this->booking->markNoShow($appointment, $this->user($request)), 'Falta registrada.');
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $dados = $request->validate(['reason' => ['nullable', 'string', 'max:64']]);

        return $this->run(fn () => $this->booking->cancel($appointment, Channel::Staff, $this->user($request), $dados['reason'] ?? null), 'Agendamento cancelado. O registro continua no histórico.');
    }

    public function editReschedule(Request $request, Appointment $appointment, ProfessionalDirectory $directory, Availability $availability): View
    {
        $user = $this->user($request);
        $servico = $this->serviceOf($appointment);
        $opcoes = $servico !== null ? $this->allowedProfessionals($user, $directory->bookableFor($servico)) : collect();
        $pro = $opcoes->firstWhere('id', $request->integer('profissional', (int) $appointment->professional_id)) ?? $opcoes->first();

        $dias = $availability->bookableDates(Channel::Staff);
        $data = (string) $request->query('data', BusinessTime::dateOf($appointment->starts_at ?? BusinessTime::now()));
        if (! in_array($data, $dias, true)) {
            $data = $dias[0] ?? BusinessTime::today();
        }

        return view('panel.agenda.reschedule', [
            'appointment' => $appointment,
            'service' => $servico,
            'professionals' => $opcoes,
            'professional' => $pro,
            'days' => $dias,
            'date' => $data,
            'slots' => $servico !== null && $pro !== null
                ? $availability->slots($servico, $pro, $data, Channel::Staff, $appointment, (int) $appointment->starts_at?->diffInMinutes($appointment->ends_at))
                : [],
        ]);
    }

    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        $user = $this->user($request);
        $dados = $request->validate([
            'professional_id' => ['required', 'integer'],
            'data' => ['required', 'string', 'size:10'],
            'hora' => ['required', 'string', 'size:5'],
        ]);
        abort_unless(BusinessTime::isValidDate($dados['data']) && BusinessTime::isValidTime($dados['hora']), 422);

        $pro = Professional::query()->findOrFail($dados['professional_id']);
        Gate::forUser($user)->authorize('createFor', [Appointment::class, $pro]);

        try {
            $this->booking->reschedule($appointment, BusinessTime::at($dados['data'], $dados['hora']), $pro, Channel::Staff, $user);
        } catch (SlotUnavailable|BookingRuleViolation $e) {
            return redirect()->route('panel.appointments.reschedule', ['appointment' => $appointment, 'profissional' => $pro->id, 'data' => $dados['data']])
                ->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->route('panel.appointments.show', $appointment)->with('status', 'Agendamento remarcado.');
    }

    /**
     * @param  \Closure(): mixed  $action
     */
    private function run(\Closure $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (BookingRuleViolation $e) {
            return back()->withErrors(['appointment' => $e->getMessage()]);
        }

        return back()->with('status', $ok);
    }

    /**
     * Profissional (manage_own) so escolhe a si mesmo.
     *
     * @param  Collection<int, Professional>  $professionals
     * @return Collection<int, Professional>
     */
    private function allowedProfessionals(User $user, Collection $professionals): Collection
    {
        return $professionals->filter(fn (Professional $p) => Gate::forUser($user)->allows('createFor', [Appointment::class, $p]))->values();
    }

    private function serviceOf(Appointment $appointment): ?Service
    {
        $id = $appointment->items()->whereNotNull('service_id')->value('service_id');

        return $id !== null ? Service::withTrashed()->find($id) : null;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

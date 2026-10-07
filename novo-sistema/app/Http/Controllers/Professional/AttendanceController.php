<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Customers\Services\CustomerNotes;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Scheduling\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Atendimentos do profissional (Fase 12.5). Lista do dia (ou busca pelo
 * nome do cliente) e a tela do atendimento pensada para usar ao lado da
 * cadeira. Tudo o que grava passa pelas rotas do painel (AttendanceService,
 * conclusao atomica da Fase 6) com a AttendancePolicy de cada registro.
 */
class AttendanceController extends Controller
{
    use ResolvesProfessional;

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);
        $data = (string) $request->query('data', BusinessTime::today());
        if (! BusinessTime::isValidDate($data)) {
            $data = BusinessTime::today();
        }
        $busca = trim(mb_substr((string) $request->query('busca', ''), 0, 60));

        $base = Attendance::query()->with('items')->where('professional_id', $pro->id);
        if ($busca !== '') {
            $lista = $base->where('customer_name', 'like', '%'.addcslashes($busca, '%_\\').'%')
                ->latest('opened_at')->paginate(20)->withQueryString();
        } else {
            $dia = BusinessTime::dayBounds($data);
            $lista = $base->where('opened_at', '>=', $dia->start)->where('opened_at', '<', $dia->end)->orderBy('opened_at')->get();
        }
        $d = CarbonImmutable::createFromFormat('Y-m-d', $data, BusinessTime::zone());

        return view('professional.attendances', [
            'date' => $data,
            'today' => BusinessTime::today(),
            'dayLabel' => $d->locale('pt_BR')->translatedFormat('l, d \d\e F'),
            'prev' => $d->subDay()->toDateString(),
            'next' => $d->addDay()->toDateString(),
            'search' => $busca,
            'attendances' => $lista,
            'canWalkIn' => $user->can('openFor', [Attendance::class, $pro]),
        ]);
    }

    public function show(Request $request, Attendance $attendance, AttendancePricing $pricing, CashRegister $cash, CustomerNotes $notes): View
    {
        $user = $this->user($request);
        $pro = $this->professional($request);
        $attendance->load(['items', 'consumptions', 'discounts', 'appointment', 'customer', 'payments', 'professional']);
        $editavel = $attendance->status->isEditable() && Gate::forUser($user)->allows('update', $attendance);
        $cliente = $attendance->customer;
        $podeAnotar = $cliente !== null && $user->can('notes', $cliente);

        // Duracao prevista: a soma dos servicos do atendimento (gravada em cada
        // item); sem isso, a do agendamento.
        $prevista = (int) $attendance->items->sum(fn (AttendanceItem $i) => (int) $i->duration_minutes);
        $origem = $attendance->appointment;
        if ($prevista === 0 && $origem?->starts_at !== null && $origem->ends_at !== null) {
            $prevista = (int) $origem->starts_at->diffInMinutes($origem->ends_at);
        }

        return view('professional.attendance', [
            'attendance' => $attendance,
            'breakdown' => $attendance->status === AttendanceStatus::Completed ? null : $pricing->breakdown($attendance),
            'editable' => $editavel,
            'services' => $editavel ? $pro->services()->bookable()->ordered()->get() : collect(),
            'productsForSale' => $editavel ? Product::query()->active()->whereNotNull('price_cents')->orderBy('name')->get() : collect(),
            'products' => $editavel ? Product::query()->active()->orderBy('name')->get() : collect(),
            'cashOpen' => $cash->current() !== null,
            'methods' => PaymentMethod::counterOptions(),
            'expectedMinutes' => $prevista,
            'now' => BusinessTime::now(),
            'canNote' => $podeAnotar,
            'notes' => $podeAnotar ? $notes->forProfessionals($cliente) : collect(),
            'noteMax' => CustomerNotes::MAX_LENGTH,
        ]);
    }
}

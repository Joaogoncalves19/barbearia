<?php

namespace App\Http\Controllers\Panel\Checkout;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceConsumption;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Checkout\Services\AttendanceCorrections;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLookup;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Support\Decimal;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Atendimento pela equipe (atendimento.md). Tudo passa pelo
 * AttendanceService / AttendanceCorrections; aqui so se le a intencao,
 * valida a entrada e mostra o resultado.
 *
 * Autorizacao: rota (Policy do registro ou habilidade) e, ao abrir ou trocar
 * o profissional, AttendancePolicy@openFor (quem so tem manage_own atende
 * so como ele mesmo).
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly AttendancePricing $pricing,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $data = (string) $request->query('data', BusinessTime::today());
        if (! BusinessTime::isValidDate($data)) {
            $data = BusinessTime::today();
        }
        $dia = BusinessTime::dayBounds($data);

        $lista = Attendance::query()
            ->where('opened_at', '>=', $dia->start)->where('opened_at', '<', $dia->end)
            ->when(! $user->can('attendances.view'), fn ($q) => $q->whereIn('professional_id', $this->ownProfessionalIds($user)))
            ->orderBy('opened_at')
            ->get();

        return view('panel.checkout.index', [
            'date' => $data,
            'attendances' => $lista,
            'open' => $lista->filter(fn (Attendance $a) => $a->status->isEditable())->values(),
            'done' => $lista->reject(fn (Attendance $a) => $a->status->isEditable())->values(),
            'canOpen' => $user->can('attendances.manage') || $user->can('attendances.manage_own'),
        ]);
    }

    public function create(Request $request, CustomerLookup $lookup): View
    {
        $user = $this->user($request);
        abort_unless($user->can('attendances.manage') || $user->can('attendances.manage_own'), 403);

        $pros = Professional::query()->where('is_active', true)->ordered()->with('services')->get()
            ->filter(fn (Professional $p) => Gate::forUser($user)->allows('openFor', [Attendance::class, $p]))->values();
        $termo = trim((string) $request->query('cliente', ''));

        return view('panel.checkout.create', [
            'professionals' => $pros,
            'services' => Service::query()->bookable()->ordered()->get(),
            'term' => $termo,
            'customers' => $lookup->search($user, $termo),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $dados = $request->validate([
            'service_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'contact_name' => ['nullable', 'required_without:customer_id', 'string', 'min:2', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:25'],
        ], ['contact_name.required_without' => 'Escolha um cliente cadastrado ou informe o nome de quem vai ser atendido.'],
            ['contact_name' => 'nome do cliente', 'contact_phone' => 'telefone', 'service_id' => 'serviço', 'professional_id' => 'profissional']);

        $pro = Professional::query()->findOrFail($dados['professional_id']);
        Gate::forUser($user)->authorize('openFor', [Attendance::class, $pro]);
        $servico = Service::query()->findOrFail($dados['service_id']);

        $cliente = null;
        if (! empty($dados['customer_id'])) {
            $cliente = Customer::query()->findOrFail($dados['customer_id']);
            Gate::forUser($user)->authorize('view', $cliente);
        }

        try {
            $at = $this->service->openWalkIn($servico, $pro, $cliente, $cliente === null ? $dados['contact_name'] : null,
                $cliente === null ? ($dados['contact_phone'] ?? null) : null, $user);
        } catch (CheckoutRuleViolation $e) {
            return back()->withInput()->withErrors(['attendance' => $e->getMessage()]);
        }

        return redirect()->route('panel.attendances.show', $at)->with('status', "Atendimento {$at->code} aberto.");
    }

    /** Cliente chegou: abre (ou reabre o mesmo) atendimento do agendamento. */
    public function openFromAppointment(Request $request, Appointment $appointment): RedirectResponse
    {
        $user = $this->user($request);
        $pro = $appointment->professional;
        abort_if($pro === null, 404);
        Gate::forUser($user)->authorize('openFor', [Attendance::class, $pro]);

        try {
            $at = $this->service->openFromAppointment($appointment, $user);
        } catch (CheckoutRuleViolation $e) {
            return back()->withErrors(['appointment' => $e->getMessage()]);
        }

        return redirect()->route('panel.attendances.show', $at)->with('status', "Atendimento {$at->code} aberto.");
    }

    public function show(Request $request, Attendance $attendance, CashRegister $cash, AttendanceCorrections $corrections): View
    {
        $user = $this->user($request);
        $attendance->load(['items', 'consumptions', 'discounts', 'events', 'appointment',
            'payments' => fn ($q) => $q->with('refunds'), 'stockMovements' => fn ($q) => $q->with(['product', 'reversal'])]);
        $editavel = $attendance->status->isEditable() && Gate::forUser($user)->allows('update', $attendance);
        $pro = $attendance->professional;

        $pagamentos = $attendance->payments;
        $estornaveis = $pagamentos->filter(fn (Payment $p) => $p->kind === PaymentKind::Payment)
            ->mapWithKeys(fn (Payment $p) => [$p->id => $corrections->refundable($p)]);

        return view('panel.checkout.show', [
            'attendance' => $attendance,
            'breakdown' => $attendance->status === AttendanceStatus::Completed ? null : $this->pricing->breakdown($attendance),
            'editable' => $editavel,
            'services' => $editavel && $pro !== null ? $pro->services()->bookable()->ordered()->get() : collect(),
            'productsForSale' => $editavel ? Product::query()->active()->whereNotNull('price_cents')->orderBy('name')->get() : collect(),
            'products' => $editavel ? Product::query()->active()->orderBy('name')->get() : collect(),
            'professionals' => $editavel ? $this->assignable($user) : collect(),
            'cashOpen' => $cash->current() !== null,
            'refundable' => $estornaveis,
            'methods' => $this->methodOptions(),
        ]);
    }

    public function start(Request $request, Attendance $attendance): RedirectResponse
    {
        return $this->run(fn () => $this->service->start($attendance, $this->user($request)), 'Atendimento iniciado.');
    }

    public function addService(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate(['service_id' => ['required', 'integer']], [], ['service_id' => 'serviço']);
        $servico = Service::query()->findOrFail($dados['service_id']);

        return $this->run(fn () => $this->service->addService($attendance, $servico, $this->user($request)), "Serviço \"{$servico->name}\" incluído.");
    }

    public function addProduct(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.AttendanceService::MAX_QUANTITY],
        ], [], ['product_id' => 'produto', 'quantity' => 'quantidade']);
        $produto = Product::query()->findOrFail($dados['product_id']);

        return $this->run(fn () => $this->service->addProduct($attendance, $produto, (int) $dados['quantity'], $this->user($request)), "Produto \"{$produto->name}\" incluído.");
    }

    public function removeItem(Request $request, Attendance $attendance, AttendanceItem $item): RedirectResponse
    {
        abort_unless($item->attendance_id === $attendance->id, 404);

        return $this->run(fn () => $this->service->removeItem($attendance, $item, $this->user($request)), 'Item retirado.');
    }

    public function addConsumption(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate([
            'consumption_product_id' => ['required', 'integer'],
            'consumption_quantity' => ['required', 'integer', 'min:1', 'max:'.AttendanceService::MAX_QUANTITY],
        ], [], ['consumption_product_id' => 'produto', 'consumption_quantity' => 'quantidade']);
        $produto = Product::query()->findOrFail($dados['consumption_product_id']);

        return $this->run(fn () => $this->service->addConsumption($attendance, $produto, (int) $dados['consumption_quantity'], $this->user($request)), 'Consumo registrado.');
    }

    public function removeConsumption(Request $request, Attendance $attendance, AttendanceConsumption $consumption): RedirectResponse
    {
        abort_unless($consumption->attendance_id === $attendance->id, 404);

        return $this->run(fn () => $this->service->removeConsumption($attendance, $consumption, $this->user($request)), 'Consumo retirado.');
    }

    public function applyDiscount(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate([
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'string', 'max:20'],
            'discount_reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['discount_type' => 'tipo', 'discount_value' => 'valor do desconto', 'discount_reason' => 'motivo']);

        try {
            $valor = Decimal::toScaledInt($dados['discount_value'], 2)['value'];
            $regra = $dados['discount_type'] === 'percent' ? Discount::percent($valor) : Discount::fixed($valor);
        } catch (InvalidArgumentException) {
            return back()->withInput()->withErrors(['discount_value' => CheckoutRuleViolation::MESSAGES['invalid_discount']]);
        }

        return $this->run(fn () => $this->service->applyDiscount($attendance, $regra, $dados['discount_reason'], $this->user($request)), 'Desconto aplicado.');
    }

    public function removeDiscount(Request $request, Attendance $attendance, AttendanceDiscount $discount): RedirectResponse
    {
        abort_unless($discount->attendance_id === $attendance->id, 404);

        return $this->run(fn () => $this->service->removeDiscount($attendance, $discount, $this->user($request)), 'Desconto retirado.');
    }

    public function changeProfessional(Request $request, Attendance $attendance): RedirectResponse
    {
        $user = $this->user($request);
        $dados = $request->validate(['professional_id' => ['required', 'integer']], [], ['professional_id' => 'profissional']);
        $pro = Professional::query()->findOrFail($dados['professional_id']);
        Gate::forUser($user)->authorize('openFor', [Attendance::class, $pro]);

        return $this->run(fn () => $this->service->changeProfessional($attendance, $pro, $user), 'Profissional alterado.');
    }

    public function updateNotes(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return $this->run(fn () => $this->service->updateNotes($attendance, $dados['notes'] ?? null, $this->user($request)), 'Observações salvas.');
    }

    /**
     * Conclui com os pagamentos informados. Repetir o mesmo envio (duplo
     * clique) devolve o atendimento ja concluido, sem duplicar nada.
     */
    public function complete(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate([
            'completion_key' => ['required', 'string', 'uuid'],
            'payments' => ['array', 'max:4'],
            'payments.*.method' => ['nullable', Rule::in(array_keys($this->methodOptions()))],
            'payments.*.amount' => ['nullable', 'string', 'max:20'],
            'payments.*.tip' => ['nullable', 'string', 'max:20'],
        ], [], ['payments.*.method' => 'forma de pagamento', 'payments.*.amount' => 'valor', 'payments.*.tip' => 'gorjeta']);

        $linhas = [];
        foreach ($dados['payments'] ?? [] as $n => $p) {
            $valorTexto = trim((string) ($p['amount'] ?? ''));
            $gorjetaTexto = trim((string) ($p['tip'] ?? ''));
            if ($valorTexto === '' && $gorjetaTexto === '') {
                continue;
            }
            $valor = $valorTexto === '' ? Money::zero() : Money::tryParse($valorTexto);
            $gorjeta = $gorjetaTexto === '' ? Money::zero() : Money::tryParse($gorjetaTexto);
            if ($valor === null || $gorjeta === null || $valor->isNegative() || $gorjeta->isNegative() || empty($p['method'])) {
                return back()->withInput()->withErrors(["payments.{$n}.amount" => 'Informe a forma e um valor válido (ex.: 50,00).']);
            }
            $linhas[] = new PaymentLine(PaymentMethod::from($p['method']), $valor->cents, $gorjeta->cents);
        }

        $user = $this->user($request);
        try {
            $at = $this->service->complete($attendance, $linhas, $dados['completion_key'], $user);
        } catch (CheckoutRuleViolation|CashRuleViolation|StockRuleViolation $e) {
            return back()->withInput()->withErrors(['complete' => $e->getMessage()]);
        }

        return redirect()->route('panel.attendances.show', $at)
            ->with('status', 'Atendimento concluído. Total: '.Money::fromCents((int) $at->total_cents)->format().'.');
    }

    public function cancel(Request $request, Attendance $attendance): RedirectResponse
    {
        $dados = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        return $this->run(fn () => $this->service->cancel($attendance, $dados['reason'], $this->user($request)), 'Atendimento cancelado. O registro continua no histórico.');
    }

    public function refund(Request $request, Attendance $attendance, Payment $payment, AttendanceCorrections $corrections): RedirectResponse
    {
        abort_unless($payment->attendance_id === $attendance->id, 404);
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'refund_amount' => ['required', 'string', 'max:20'],
            'refund_reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['refund_amount' => 'valor do estorno', 'refund_reason' => 'motivo']);

        $valor = Money::tryParse($dados['refund_amount']);
        if ($valor === null || $valor->cents < 1) {
            return back()->withErrors(['refund' => 'Informe um valor válido (ex.: 20,00).']);
        }

        try {
            $corrections->refund($payment, $valor->cents, $dados['refund_reason'], $this->user($request), $dados['request_key']);
        } catch (CashRuleViolation $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Estorno de '.$valor->format().' registrado. O pagamento original continua no histórico.');
    }

    public function returnToStock(Request $request, Attendance $attendance, StockMovement $movement, AttendanceCorrections $corrections): RedirectResponse
    {
        abort_unless($movement->attendance_id === $attendance->id, 404);
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'return_reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['return_reason' => 'motivo']);

        try {
            $corrections->returnToStock($movement, $dados['return_reason'], $this->user($request), $dados['request_key']);
        } catch (StockRuleViolation $e) {
            return back()->withErrors(['attendance' => $e->getMessage()]);
        }

        return back()->with('status', 'Produto devolvido ao estoque.');
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function run(Closure $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (CheckoutRuleViolation|CashRuleViolation|StockRuleViolation $e) {
            return back()->withInput()->withErrors(['attendance' => $e->getMessage()]);
        }

        return back()->with('status', $ok);
    }

    /**
     * Profissionais para quem esta pessoa pode passar/abrir o atendimento.
     *
     * @return Collection<int, Professional>
     */
    private function assignable(User $user): Collection
    {
        return Professional::query()->where('is_active', true)->ordered()->get()
            ->filter(fn (Professional $p) => Gate::forUser($user)->allows('openFor', [Attendance::class, $p]))->values();
    }

    /**
     * @return list<int>
     */
    private function ownProfessionalIds(User $user): array
    {
        return Professional::withTrashed()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Formas aceitas no balcao ("nao informado" so existe no legado).
     *
     * @return array<string, string>
     */
    private function methodOptions(): array
    {
        $formas = [];
        foreach (PaymentMethod::cases() as $m) {
            if ($m !== PaymentMethod::Unknown) {
                $formas[$m->value] = $m->label();
            }
        }

        return $formas;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

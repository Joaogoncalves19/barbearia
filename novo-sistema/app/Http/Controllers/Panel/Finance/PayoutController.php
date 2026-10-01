<?php

namespace App\Http\Controllers\Panel\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Identity\Models\User;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Services\Receipts;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Repasse (repasses.md §5). Fechar, ver e estornar passam pelo Payouts.
 * Autorizacao na rota: payouts.view / payouts.create / payouts.reverse;
 * o profissional ve so os proprios (CommissionPayoutPolicy).
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly Payouts $payouts,
        private readonly ProfessionalLedger $ledger,
    ) {}

    public function index(Request $request): View
    {
        $pro = $request->integer('profissional') ?: null;

        return view('panel.finance.payouts.index', [
            'payouts' => CommissionPayout::query()->with(['professional', 'createdBy'])
                ->when($pro, fn ($q) => $q->where('professional_id', $pro))
                ->latest('id')->limit(100)->get(),
            'professionals' => Professional::withTrashed()->ordered()->get(),
            'selected' => $pro,
        ]);
    }

    /** Conferencia antes de pagar: o que esta em aberto ate agora. */
    public function create(Professional $professional): View
    {
        $agora = BusinessTime::now();

        return view('panel.finance.payouts.create', [
            'professional' => $professional,
            'open' => $this->ledger->open($professional, $agora),
            'commissions' => $this->ledger->openCommission($professional->id, $agora)->with('attendance')->orderBy('occurred_at')->get(),
            'tips' => $this->ledger->openTips($professional->id, $agora)->with('attendance')->orderBy('occurred_at')->get(),
            'advances' => $this->ledger->openAdvances($professional->id, $agora)->orderBy('occurred_at')->get(),
            'methods' => Payouts::METHODS,
        ]);
    }

    public function store(Request $request, Professional $professional): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, Payouts::METHODS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['method' => 'forma de pagamento', 'notes' => 'observação']);

        try {
            $p = $this->payouts->pay($professional, PaymentMethod::from($dados['method']), $dados['notes'] ?? null, $this->user($request), $dados['request_key']);
        } catch (CommissionRuleViolation|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['payout' => $e->getMessage()]);
        }

        return redirect()->route('panel.payouts.show', $p)->with('status', 'Repasse de '.Money::fromCents($p->amount_cents)->format().' registrado.');
    }

    public function show(CommissionPayout $payout, Receipts $receipts): View
    {
        // Os lancamentos do repasse vem da mesma fonte do recibo impresso.
        return view('panel.finance.payouts.show', $receipts->data(ReceiptType::Payout, $payout->id));
    }

    public function reverse(Request $request, CommissionPayout $payout): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['reason' => 'motivo']);

        try {
            $this->payouts->reverse($payout, $dados['reason'], $this->user($request), $dados['request_key']);
        } catch (CommissionRuleViolation|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', 'Repasse estornado. Os valores voltaram ao saldo em aberto do profissional.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

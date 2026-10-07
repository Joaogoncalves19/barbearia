<?php

namespace App\Http\Controllers\Panel\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Comissoes e extrato do profissional (comissoes.md, repasses.md). Le e
 * mostra; toda gravacao passa por ProfessionalLedger/Payouts/Advances.
 * Autorizacao na rota: commissions.view, viewLedger (o proprio) e
 * commissions.correct (ajuste).
 */
class CommissionController extends Controller
{
    public function __construct(private readonly ProfessionalLedger $ledger) {}

    /** Saldo em aberto de cada profissional (ativo, ou inativo com saldo). */
    public function index(): View
    {
        $linhas = Professional::withTrashed()->ordered()->get()
            ->map(fn (Professional $p) => ['professional' => $p, 'open' => $this->ledger->open($p)])
            ->filter(fn (array $l) => $l['professional']->is_active && ! $l['professional']->trashed()
                || $l['open']['commission'] !== 0 || $l['open']['tips'] !== 0 || $l['open']['advances'] !== 0)
            ->values();

        return view('panel.finance.commissions.index', [
            'rows' => $linhas,
            'totals' => [
                'commission' => $linhas->sum(fn ($l) => $l['open']['commission']),
                'tips' => $linhas->sum(fn ($l) => $l['open']['tips']),
                'advances' => $linhas->sum(fn ($l) => $l['open']['advances']),
                'net' => $linhas->sum(fn ($l) => $l['open']['net']),
            ],
        ]);
    }

    /** O profissional vai direto ao proprio extrato. */
    public function mine(Request $request): RedirectResponse
    {
        $pro = Professional::query()->where('user_id', $this->user($request)->id)->first();
        abort_if($pro === null, 404);

        return redirect()->route('panel.commissions.show', $pro);
    }

    public function show(Request $request, Professional $professional): View
    {
        $mes = (string) $request->query('mes', substr(BusinessTime::today(), 0, 7));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $mes = substr(BusinessTime::today(), 0, 7);
        }
        $inicio = BusinessTime::at($mes.'-01', '00:00');
        $extrato = $this->ledger->month($professional, $mes);

        return view('panel.finance.commissions.show', [
            'professional' => $professional,
            'month' => $mes,
            'previousMonth' => $inicio->setTimezone(BusinessTime::zone())->subMonth()->format('Y-m'),
            'nextMonth' => $inicio->setTimezone(BusinessTime::zone())->addMonth()->format('Y-m'),
            'open' => $this->ledger->open($professional),
            'commissions' => $extrato['commissions'],
            'tips' => $extrato['tips'],
            'advances' => $extrato['advances'],
            'payouts' => CommissionPayout::query()->where('professional_id', $professional->id)->latest('id')->limit(12)->get(),
            'methods' => Payouts::METHODS,
        ]);
    }

    public function adjust(Request $request, Professional $professional): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'ledger' => ['required', Rule::in(['commission', 'tip'])],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'attendance_code' => ['nullable', 'string', 'max:20'],
        ], [], ['ledger' => 'tipo', 'direction' => 'sentido', 'amount' => 'valor', 'reason' => 'motivo', 'attendance_code' => 'atendimento']);

        $valor = Money::tryParse($dados['amount']);
        if ($valor === null || $valor->cents < 1) {
            return back()->withInput()->withErrors(['amount' => 'Informe um valor maior que zero (ex.: 10,00).']);
        }
        $atendimento = null;
        $codigo = strtoupper(trim((string) ($dados['attendance_code'] ?? '')));
        if ($codigo !== '') {
            $atendimento = Attendance::query()->where('code', $codigo)->first();
            if ($atendimento === null) {
                return back()->withInput()->withErrors(['attendance_code' => CommissionRuleViolation::MESSAGES['unknown_attendance']]);
            }
        }

        try {
            $this->ledger->adjust($professional, $dados['ledger'], $dados['direction'] === 'debit' ? -$valor->cents : $valor->cents,
                $dados['reason'], $atendimento, $this->user($request), $dados['request_key']);
        } catch (CommissionRuleViolation $e) {
            return back()->withInput()->withErrors(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Ajuste de '.($dados['ledger'] === 'tip' ? 'gorjeta' : 'comissão').' registrado.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

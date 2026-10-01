<?php

namespace App\Http\Controllers\Panel\Checkout;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Caixa (caixa.md). Tudo passa pelo CashRegister; aqui so se le a intencao.
 * Autorizacao na rota: cash.view / cash.open / cash.move / cash.close.
 */
class CashController extends Controller
{
    public function __construct(private readonly CashRegister $cash) {}

    public function index(): View
    {
        $atual = $this->cash->current();

        return view('panel.cash.index', [
            'session' => $atual,
            'summary' => $atual !== null ? $this->cash->summary($atual) : null,
            'movements' => $atual !== null ? $atual->movements()->with('createdBy')->latest('id')->get() : collect(),
            'history' => CashSession::query()->where('status', 'closed')->with(['openedBy', 'closedBy'])->latest('closed_at')->limit(15)->get(),
        ]);
    }

    public function show(CashSession $session): View
    {
        return view('panel.cash.show', [
            'session' => $session->load(['openedBy', 'closedBy']),
            'summary' => $this->cash->summary($session),
            'movements' => $session->movements()->with('createdBy')->get(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'opening_float' => ['required', 'string', 'max:20'],
            'opening_notes' => ['nullable', 'string', 'max:255'],
        ], [], ['opening_float' => 'valor inicial', 'opening_notes' => 'observação']);

        $valor = Money::tryParse($dados['opening_float']);
        if ($valor === null || $valor->isNegative()) {
            return back()->withInput()->withErrors(['opening_float' => 'Informe o dinheiro que está na gaveta (ex.: 100,00 ou 0).']);
        }

        try {
            $this->cash->open($valor->cents, $dados['opening_notes'] ?? null, $this->user($request));
        } catch (CashRuleViolation $e) {
            return back()->withErrors(['cash' => $e->getMessage()]);
        }

        return redirect()->route('panel.cash.index')->with('status', 'Caixa aberto com '.$valor->format().'.');
    }

    public function move(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'type' => ['required', Rule::in(['supply', 'withdrawal'])],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['amount' => 'valor', 'reason' => 'motivo', 'type' => 'tipo']);

        $valor = Money::tryParse($dados['amount']);
        if ($valor === null || $valor->cents < 1) {
            return back()->withInput()->withErrors(['amount' => 'Informe um valor maior que zero (ex.: 50,00).']);
        }
        $caixa = $this->cash->current();
        if ($caixa === null) {
            return back()->withErrors(['cash' => CashRuleViolation::MESSAGES['no_open_session']]);
        }

        try {
            $dados['type'] === 'supply'
                ? $this->cash->supply($caixa, $valor->cents, $dados['reason'], $this->user($request), $dados['request_key'])
                : $this->cash->withdraw($caixa, $valor->cents, $dados['reason'], $this->user($request), $dados['request_key']);
        } catch (CashRuleViolation $e) {
            return back()->withInput()->withErrors(['cash' => $e->getMessage()]);
        }

        return back()->with('status', ($dados['type'] === 'supply' ? 'Suprimento' : 'Sangria').' de '.$valor->format().' registrado.');
    }

    public function close(Request $request, CashSession $session): RedirectResponse
    {
        $dados = $request->validate([
            'counted' => ['required', 'string', 'max:20'],
            'closing_notes' => ['nullable', 'string', 'max:255'],
        ], [], ['counted' => 'dinheiro contado', 'closing_notes' => 'justificativa']);

        $valor = Money::tryParse($dados['counted']);
        if ($valor === null || $valor->isNegative()) {
            return back()->withInput()->withErrors(['counted' => 'Informe o dinheiro contado na gaveta (ex.: 250,00).']);
        }

        try {
            $s = $this->cash->close($session, $valor->cents, $dados['closing_notes'] ?? null, $this->user($request));
        } catch (CashRuleViolation $e) {
            return back()->withInput()->withErrors(['cash' => $e->getMessage()]);
        }

        $dif = (int) $s->difference_cents;

        return redirect()->route('panel.cash.show', $s)->with('status', 'Caixa fechado. '.($dif === 0 ? 'Sem diferença.' : 'Diferença: '.Money::fromCents($dif)->format().'.'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

<?php

namespace App\Http\Controllers\Panel\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Services\Advances;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vales (repasses.md §4). Autorizacao na rota: advances.create / advances.reverse.
 */
class AdvanceController extends Controller
{
    public function __construct(private readonly Advances $advances) {}

    public function store(Request $request, Professional $professional): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, Payouts::METHODS))],
            'description' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['amount' => 'valor', 'method' => 'forma de pagamento', 'description' => 'motivo']);

        $valor = Money::tryParse($dados['amount']);
        if ($valor === null || $valor->cents < 1) {
            return back()->withInput()->withErrors(['amount' => 'Informe um valor maior que zero (ex.: 50,00).']);
        }

        try {
            $this->advances->issue($professional, $valor->cents, PaymentMethod::from($dados['method']), $dados['description'], $this->user($request), $dados['request_key']);
        } catch (CommissionRuleViolation|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['advance' => $e->getMessage()]);
        }

        return back()->with('status', 'Vale de '.$valor->format().' registrado. Será abatido no próximo repasse.');
    }

    public function reverse(Request $request, Advance $advance): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['reason' => 'motivo']);

        try {
            $this->advances->reverse($advance, $dados['reason'], $this->user($request), $dados['request_key']);
        } catch (CommissionRuleViolation|CashRuleViolation $e) {
            return back()->withInput()->withErrors(['advance' => $e->getMessage()]);
        }

        return back()->with('status', 'Vale estornado.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

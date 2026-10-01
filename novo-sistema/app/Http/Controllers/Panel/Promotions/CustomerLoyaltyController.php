<?php

namespace App\Http\Controllers\Panel\Promotions;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pontos do cliente no painel: buscar, ver saldo/disponivel/extrato e
 * ajustar (com motivo). Autorizacao na rota: loyalty.view / loyalty.adjust.
 */
class CustomerLoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyLedger $ledger) {}

    public function index(Request $request): View
    {
        $termo = trim((string) $request->query('busca', ''));
        $digitos = preg_replace('/\D+/', '', $termo) ?? '';
        $clientes = mb_strlen($termo) < 2 ? collect() : Customer::query()
            ->whereNull('merged_into_customer_id')->whereNull('anonymized_at')
            ->where(function ($q) use ($termo, $digitos) {
                $q->where('name', 'like', '%'.$termo.'%')->orWhere('email', 'like', '%'.mb_strtolower($termo).'%');
                if (strlen($digitos) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digitos.'%');
                }
            })->orderBy('name')->limit(20)->get();

        return view('panel.promotions.loyalty.index', [
            'term' => $termo,
            'customers' => $clientes->map(fn (Customer $c) => ['customer' => $c, 'balance' => $this->ledger->balance($c)]),
        ]);
    }

    public function show(Customer $customer): View
    {
        return view('panel.promotions.loyalty.show', [
            'customer' => $customer,
            'balance' => $this->ledger->balance($customer),
            'available' => $this->ledger->available($customer),
            'reserved' => LoyaltyRedemption::query()->where('customer_id', $customer->id)->where('status', RedemptionStatus::Reserved)->with('appointment')->get(),
            'entries' => LoyaltyEntry::query()->where('customer_id', $customer->id)->with(['attendance', 'createdBy'])->latest('id')->limit(100)->get(),
        ]);
    }

    public function adjust(Request $request, Customer $customer): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['points' => 'pontos', 'reason' => 'motivo', 'direction' => 'sentido']);

        try {
            $pontos = $dados['direction'] === 'debit' ? -(int) $dados['points'] : (int) $dados['points'];
            $this->ledger->adjust($customer, $pontos, $dados['reason'], $this->user($request), $dados['request_key']);
        } catch (PromotionRejected $e) {
            return back()->withInput()->withErrors(['loyalty' => $e->getMessage()]);
        }

        return back()->with('status', 'Ajuste de pontos registrado.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

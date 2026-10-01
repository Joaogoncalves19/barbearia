<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fidelidade e indicacao na conta do cliente (fidelidade.md §6): saldo,
 * pontos disponiveis, extrato, como ganhar e resgatar, e o proprio codigo de
 * indicacao. So o proprio cliente.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyLedger $ledger) {}

    public function show(Request $request): View
    {
        $cliente = $this->customer($request);

        return view('account.loyalty', [
            'customer' => $cliente,
            'balance' => $this->ledger->balance($cliente),
            'available' => $this->ledger->available($cliente),
            'entries' => LoyaltyEntry::query()->where('customer_id', $cliente->id)->latest('id')->limit(50)->get(),
            'policy' => PromotionPolicy::current(),
        ]);
    }

    /** Clientes antigos sem codigo: gera um (so uma vez). */
    public function generateCode(Request $request): RedirectResponse
    {
        $cliente = $this->customer($request);
        if ($cliente->referral_code === null) {
            $cliente->forceFill(['referral_code' => Customer::newReferralCode()])->save();
        }

        return back()->with('status', 'Seu código de indicação está pronto.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}

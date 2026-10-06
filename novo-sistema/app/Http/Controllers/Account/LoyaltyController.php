<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Beneficios, fidelidade e indicacao na conta do cliente (fidelidade.md §6): o
 * que vale hoje (PromotionEngine::entitlements), saldo,
 * pontos disponiveis, extrato, como ganhar e resgatar, e o proprio codigo de
 * indicacao. So o proprio cliente.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyLedger $ledger) {}

    public function show(Request $request, PromotionEngine $engine): View
    {
        $cliente = $this->customer($request);

        return view('account.loyalty', [
            'customer' => $cliente,
            'balance' => $this->ledger->balance($cliente),
            'available' => $this->ledger->available($cliente),
            'entries' => LoyaltyEntry::query()->where('customer_id', $cliente->id)->latest('id')->limit(50)->get(),
            'policy' => PromotionPolicy::current(),
            // Fase 12: o que vale hoje, pelo mesmo motor do orcamento (nada vem do navegador).
            'entitlements' => $engine->entitlements($cliente, BusinessTime::today()),
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

<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionEvent;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use App\Modules\Subscriptions\Services\SubscriptionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A assinatura na conta do cliente (assinaturas.md §7): plano, situacao,
 * beneficio, pagamentos, link para concluir o pagamento, cancelar a
 * renovacao (no fim do periodo) e desfazer o cancelamento. So a propria.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionManager $manager,
        private readonly SubscriptionBenefits $benefits,
    ) {}

    public function show(Request $request): View
    {
        $cliente = $this->customer($request);
        $atual = $this->current($cliente);

        return view('account.subscription', [
            'subscription' => $atual?->load(['plan', 'planVersion.services']),
            'benefitToday' => $atual !== null && $this->benefits->validOn($atual, BusinessTime::today()),
            'payments' => SubscriptionPayment::query()->where('customer_id', $cliente->id)->with('refunds')->latest('paid_at')->limit(24)->get(),
            // Fase 12: historico relevante para o cliente (sem sincronizacoes tecnicas nem observacoes da equipe).
            'events' => $atual === null ? collect() : SubscriptionEvent::query()->where('subscription_id', $atual->id)
                ->whereNotIn('kind', [SubscriptionEventKind::StatusSynced->value, SubscriptionEventKind::CheckoutStarted->value, SubscriptionEventKind::PaymentPending->value])
                ->orderByDesc('effective_at')->orderByDesc('id')->limit(30)->get(),
            'paymentReturn' => (string) $request->query('pagamento', ''),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $cliente = $this->customer($request);
        $s = $this->current($cliente);
        abort_if($s === null, 404);
        $dados = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $s = $this->manager->cancel($s, false, $dados['reason'] ?? null, $cliente);
        } catch (SubscriptionRuleViolation $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', 'Renovação cancelada. Não haverá nova cobrança; seus benefícios seguem até '.($s->ends_on?->format('d/m/Y') ?? 'o fim do período pago').'.');
    }

    public function reactivate(Request $request): RedirectResponse
    {
        $cliente = $this->customer($request);
        $s = $this->current($cliente);
        abort_if($s === null, 404);

        try {
            $this->manager->reactivate($s, $cliente);
        } catch (SubscriptionRuleViolation $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', 'Assinatura reativada: a cobrança mensal continua normalmente.');
    }

    /** A assinatura vigente, ou a mais recente com beneficio ainda valendo. */
    private function current(Customer $customer): ?Subscription
    {
        return Subscription::query()->where('customer_id', $customer->id)->whereIn('status', SubscriptionStatus::currentValues())->first()
            ?? Subscription::query()->where('customer_id', $customer->id)->latest('id')->first();
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}

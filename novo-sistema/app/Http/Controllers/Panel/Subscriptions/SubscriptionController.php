<?php

namespace App\Http\Controllers\Panel\Subscriptions;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use App\Modules\Subscriptions\Services\SubscriptionCheckout;
use App\Modules\Subscriptions\Services\SubscriptionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Assinaturas no painel (assinaturas.md §7). Cada acao tem a sua permissao
 * (rota): ver, ver pagamentos, gerar link, cancelar, reativar, reembolsar,
 * historico. Nenhuma tela grava o estado direto: tudo passa pelos servicos.
 * As chaves do Stripe nunca aparecem (so "configurado" ou nao).
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionManager $manager,
        private readonly SubscriptionCheckout $checkout,
        private readonly SubscriptionBenefits $benefits,
    ) {}

    public function index(Request $request): View
    {
        $filtro = (string) $request->query('situacao', 'vigentes');
        $busca = trim((string) $request->query('busca', ''));
        $q = Subscription::query()->with(['customer', 'plan', 'planVersion'])
            ->when($filtro === 'vigentes', fn (Builder $q) => $q->whereIn('status', SubscriptionStatus::currentValues()))
            ->when($filtro !== 'vigentes' && $filtro !== 'todas' && SubscriptionStatus::tryFrom($filtro) !== null, fn (Builder $q) => $q->where('status', $filtro))
            ->when($busca !== '', fn (Builder $q) => $q->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', '%'.$busca.'%')->orWhere('email', 'like', '%'.$busca.'%')))
            ->latest('id');

        // MRR: assinaturas que renovam no proximo ciclo (cancelamento agendado nao entra), pelo preco contratado.
        $mrr = (int) DB::table('subscriptions')->join('plan_versions', 'plan_versions.id', '=', 'subscriptions.plan_version_id')
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->sum('plan_versions.price_cents');

        return view('panel.subscriptions.index', [
            'subscriptions' => $q->limit(200)->get(),
            'filter' => $filtro,
            'search' => $busca,
            'counts' => Subscription::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'withBenefit' => Subscription::query()->whereIn('status', SubscriptionStatus::currentValues())->get()->filter(fn (Subscription $s) => $this->benefits->validOn($s, BusinessTime::today()))->count(),
            'mrr' => $mrr,
            'stripeReady' => $this->checkout->available(),
        ]);
    }

    public function show(Request $request, Subscription $subscription): View
    {
        $s = $subscription->load(['customer', 'plan', 'planVersion.services', 'signupAppointment', 'events']);
        $podePagamentos = $request->user('web')?->can('subscriptions.payments') ?? false;

        return view('panel.subscriptions.show', [
            's' => $s,
            'payments' => $podePagamentos ? SubscriptionPayment::query()->with('refunds')->where('subscription_id', $s->id)->latest('paid_at')->get() : collect(),
            'canSeePayments' => $podePagamentos,
            'benefitToday' => $this->benefits->validOn($s, BusinessTime::today()),
            'actors' => User::query()->whereIn('id', $s->events->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id'),
        ]);
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $dados = $request->validate([
            'mode' => ['required', 'in:end,now'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['mode' => 'quando', 'reason' => 'motivo']);

        try {
            $s = $this->manager->cancel($subscription, $dados['mode'] === 'now', $dados['reason'], $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withInput()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', $s->status === SubscriptionStatus::Cancelled
            ? 'Assinatura cancelada. Benefício '.($s->ends_on ? 'até '.$s->ends_on->format('d/m/Y') : 'encerrado').'.'
            : 'Cancelamento agendado: sem nova cobrança; benefício até '.($s->ends_on?->format('d/m/Y') ?? 'o fim do período').'.');
    }

    public function reactivate(Request $request, Subscription $subscription): RedirectResponse
    {
        try {
            $this->manager->reactivate($subscription, $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', 'Assinatura reativada: a cobrança mensal continua normalmente.');
    }

    public function refund(Request $request, SubscriptionPayment $payment): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['amount' => 'valor', 'reason' => 'motivo']);
        $valor = Money::tryParse($dados['amount']);
        if ($valor === null) {
            return back()->withInput()->withErrors(['refund' => 'Informe o valor (ex.: 49,90).']);
        }

        try {
            $r = $this->manager->refund($payment, $valor->cents, $dados['reason'], $this->user($request), $dados['request_key']);
        } catch (SubscriptionRuleViolation $e) {
            return back()->withInput()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Reembolso de '.Money::fromCents($r->amount_cents)->format().' '.match ($r->status) {
            'succeeded' => 'concluído no Stripe.', 'pending' => 'pedido ao Stripe (em processamento).', default => 'recusado pelo Stripe.',
        });
    }

    public function newLink(Request $request): View
    {
        $busca = trim((string) $request->query('busca', ''));

        return view('panel.subscriptions.link', [
            'search' => $busca,
            'customers' => mb_strlen($busca) >= 2 ? Customer::query()->where(fn (Builder $q) => $q->where('name', 'like', '%'.$busca.'%')
                ->orWhere('email', 'like', '%'.$busca.'%')->orWhere('phone', 'like', '%'.$busca.'%'))->orderBy('name')->limit(20)->get() : collect(),
            'plans' => Plan::query()->with('currentVersion')->where('is_active', true)->orderBy('name')->get()
                ->filter(fn (Plan $p) => $p->currentVersion !== null)->mapWithKeys(fn (Plan $p) => [$p->id => $p->name.' · '.$p->currentVersion?->priceLabel()])->all(),
            'stripeReady' => $this->checkout->available(),
        ]);
    }

    public function storeLink(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'customer' => ['required', 'string', 'max:64'],
            'plan_id' => ['required', 'integer'],
        ], [], ['customer' => 'cliente', 'plan_id' => 'plano']);
        $cliente = Customer::query()->where('public_id', $dados['customer'])->firstOrFail();
        $plano = Plan::query()->findOrFail($dados['plan_id']);

        try {
            $s = $this->checkout->start($cliente, $plano, SubscriptionOrigin::Panel, null, $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withInput()->withErrors(['subscription' => $e->getMessage()]);
        }

        return redirect()->route('panel.subscriptions.show', $s)->with('status', 'Link de pagamento gerado. Envie ao cliente; ele também aparece na conta dele. A assinatura ativa quando o Stripe confirmar o pagamento.');
    }

    /** P10-04: envia por e-mail o link ja gerado (nunca gera outro). */
    public function emailLink(Request $request, Subscription $subscription): RedirectResponse
    {
        try {
            $novo = $this->checkout->emailLink($subscription, $this->user($request));
        } catch (SubscriptionRuleViolation $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', $novo ? 'Link enviado para o e-mail do cliente (sai pela fila em instantes).' : 'Este link já tinha sido enviado por e-mail.');
    }

    public function events(Request $request): View
    {
        $filtro = (string) $request->query('situacao', 'todos');

        return view('panel.subscriptions.events', [
            'events' => GatewayEvent::query()->where('gateway', 'stripe')->with('subscription:id,public_id')
                ->when($filtro === 'problemas', fn (Builder $q) => $q->where(fn (Builder $x) => $x->whereIn('status', ['failed', 'received'])->orWhere('result', 'unmatched')))
                ->latest('id')->limit(200)->get(['id', 'event_id', 'type', 'status', 'result', 'object_id', 'event_created_at', 'received_at', 'processed_at', 'attempts', 'last_error', 'subscription_id', 'livemode']),
            'filter' => $filtro,
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

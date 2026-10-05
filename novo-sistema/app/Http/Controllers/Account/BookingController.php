<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionCheckout;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmacao do agendamento pelo cliente logado (ultima etapa do fluxo do
 * site). A escolha (servico, profissional, dia, hora) chega pela URL e e
 * REVALIDADA aqui e no BookingService: manipular a URL nunca reserva algo
 * que a regra nao aceita.
 */
class BookingController extends Controller
{
    public function confirm(Request $request, ProfessionalDirectory $directory, Availability $availability, PromotionEngine $promotions): View|RedirectResponse
    {
        [$servico, $pro, $inicio] = $this->choice($request, $directory);

        $livre = $pro !== null
            ? $availability->check($servico, $pro, $inicio, Channel::Customer)
            : null;
        $semPreferencia = $pro === null
            ? collect($availability->slots($servico, null, BusinessTime::dateOf($inicio), Channel::Customer))->first(fn ($s) => $s['start']->eq($inicio))
            : null;

        if (($livre !== null && ! $livre->isAvailable()) || ($pro === null && $semPreferencia === null)) {
            return $this->backToSlots($servico, $pro, $inicio, $livre?->message() ?? 'Este horário acabou de ser ocupado. Escolha outro.');
        }

        // Previa do valor (Fase 8): o MESMO motor que grava no agendamento.
        /** @var Customer $cliente */
        $cliente = $request->user('customer');
        $pedido = $this->promotionRequest($request);
        $preco = (int) $servico->price_cents;
        $quote = $promotions->quote($cliente, [['total' => $preco, 'discountable' => true, 'unit' => $preco, 'service' => $servico->id]], BusinessTime::dateOf($inicio), $pedido);

        return view('account.booking-confirm', [
            'quote' => $quote,
            'promotion' => $pedido,
            'loyaltyAvailable' => app(LoyaltyLedger::class)->available($cliente),
            'policy' => PromotionPolicy::current(),
            'service' => $servico,
            'professional' => $pro ?? $semPreferencia['professionals'][0],
            'anyProfessional' => $pro === null,
            'start' => $inicio,
            'end' => $inicio->addMinutes($servico->duration_minutes),
            'query' => $request->only(['servico', 'profissional', 'data', 'hora']),
            'plans' => $this->signupPlans($cliente, $servico),
            'chosenPlan' => $request->integer('plano') ?: null,
        ]);
    }

    /**
     * Fase 9 (D-47, como no sistema antigo): planos que o cliente pode assinar
     * ao agendar. So com pagamento online configurado (R-25), sem assinatura
     * vigente, com e-mail. Cada um diz se este servico sai de graca.
     *
     * @return list<array{plan: Plan, price: string, covers: bool}>
     */
    private function signupPlans(Customer $customer, Service $service): array
    {
        if (! app(SubscriptionCheckout::class)->available() || $customer->email === null
            || Subscription::query()->where('customer_id', $customer->id)->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::CancelScheduled->value])->exists()) {
            return [];
        }

        return Plan::query()->with('currentVersion.services')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Plan $p) => $p->currentVersion !== null)
            ->map(fn (Plan $p) => ['plan' => $p, 'price' => $p->currentVersion?->priceLabel() ?? '', 'covers' => in_array($service->id, $p->currentVersion?->serviceIds() ?? [], true)])
            ->values()->all();
    }

    public function store(Request $request, ProfessionalDirectory $directory, BookingService $booking): RedirectResponse
    {
        [$servico, $pro, $inicio] = $this->choice($request, $directory);

        /** @var Customer $cliente */
        $cliente = $request->user('customer');

        try {
            $a = $booking->book(new BookingRequest(
                service: $servico,
                professional: $pro,
                start: $inicio,
                channel: Channel::Customer,
                source: AppointmentSource::Online,
                customer: $cliente,
                notes: $request->filled('notes') ? mb_substr(trim($request->string('notes')->value()), 0, 500) : null,
                actor: $cliente,
                promotion: $this->promotionRequest($request),
                expectedTotalCents: $request->filled('expected_total') ? (int) $request->input('expected_total') : null,
            ));
        } catch (SlotUnavailable $e) {
            return $this->backToSlots($servico, $pro, $inicio, $e->getMessage());
        } catch (BookingRuleViolation $e) {
            return $this->backToSlots($servico, $pro, $inicio, $e->getMessage());
        } catch (PromotionRejected $e) {
            // Cupom/pontos recusados ou valor mudou: volta a confirmacao com o motivo (nada gravado).
            return redirect()->route('account.booking.confirm', $request->only(['servico', 'profissional', 'data', 'hora', 'cupom', 'pontos']))
                ->withErrors(['promotion' => $e->getMessage()]);
        }

        // Adesao no agendamento (Fase 9): o agendamento ja esta feito pelo valor
        // normal; o beneficio entra quando o Stripe confirmar o pagamento.
        if ($request->filled('plano')) {
            $plano = Plan::query()->find($request->integer('plano'));
            try {
                if ($plano === null) {
                    throw new SubscriptionRuleViolation('plan_unavailable');
                }
                app(SubscriptionCheckout::class)->start($cliente, $plano, SubscriptionOrigin::Booking, $a, null);
            } catch (SubscriptionRuleViolation $e) {
                return redirect()->route('account.appointments.show', $a)
                    ->with('status', 'Agendamento feito! Código '.$a->code.'.')
                    ->withErrors(['appointment' => 'A assinatura não foi iniciada: '.$e->getMessage().' O agendamento vale pelo valor normal.']);
            }

            return redirect()->route('account.subscription')->with('status', 'Agendamento feito! Código '.$a->code.'. Agora conclua o pagamento da assinatura: quando o Stripe confirmar, o benefício entra neste agendamento.');
        }

        return redirect()->route('account.appointments.show', $a)->with('status', 'Agendamento feito! Código '.$a->code.'.');
    }

    /**
     * Le e valida a escolha. Qualquer valor invalido = 404 (nao ha o que
     * mostrar), nunca uma reserva "aproximada".
     *
     * @return array{0: Service, 1: ?Professional, 2: CarbonImmutable}
     */
    private function choice(Request $request, ProfessionalDirectory $directory): array
    {
        $dados = $request->validate([
            'servico' => ['required', 'string', 'max:80'],
            'profissional' => ['required', 'string', 'max:80'],
            'data' => ['required', 'string', 'size:10'],
            'hora' => ['required', 'string', 'size:5'],
            'notes' => ['nullable', 'string', 'max:500'],
            'cupom' => ['nullable', 'string', 'max:64'],
            'pontos' => ['nullable', 'boolean'],
            'expected_total' => ['nullable', 'integer', 'min:0'],
            'plano' => ['nullable', 'integer'],
        ]);

        abort_unless(BusinessTime::isValidDate($dados['data']) && BusinessTime::isValidTime($dados['hora']), 404);

        $servico = Service::query()->bookable()->where('slug', $dados['servico'])->first();
        abort_if($servico === null, 404);

        $pro = null;
        if ($dados['profissional'] !== 'qualquer') {
            $pro = $directory->bookableFor($servico)->first(fn (Professional $p) => $p->slug === $dados['profissional']);
            abort_if($pro === null, 404);
        }

        return [$servico, $pro, BusinessTime::at($dados['data'], $dados['hora'])];
    }

    private function promotionRequest(Request $request): PromotionRequest
    {
        return new PromotionRequest($request->string('cupom')->value() ?: null, $request->boolean('pontos'));
    }

    private function backToSlots(Service $service, ?Professional $pro, CarbonImmutable $start, string $message): RedirectResponse
    {
        return redirect()->route('booking.slots', [
            'service' => $service->slug,
            'profissional' => $pro !== null ? $pro->slug : 'qualquer',
            'data' => BusinessTime::dateOf($start),
        ])->withErrors(['slot' => $message]);
    }
}

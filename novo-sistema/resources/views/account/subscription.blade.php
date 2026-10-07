@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use App\Modules\Subscriptions\Enums\SubscriptionStatus;
    $s = $subscription;
    $st = $s?->status;
@endphp
<x-layouts.account title="Assinatura">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Assinatura</h1>
    </header>

    @error('subscription')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @if ($paymentReturn === 'ok' && $st === SubscriptionStatus::Pending)
        <x-ui.alert>Recebemos o seu retorno do Stripe. A assinatura ativa assim que o Stripe confirmar o pagamento (normalmente em instantes): atualize esta página.</x-ui.alert>
    @elseif ($paymentReturn === 'cancelado' && $st === SubscriptionStatus::Pending)
        <x-ui.alert variant="warning">O pagamento não foi concluído. Você pode tentar de novo pelo botão abaixo enquanto o link valer.</x-ui.alert>
    @endif

    @if ($s === null)
        <x-ui.card title="Você não tem assinatura">
            <p>Assine um plano ao agendar: os serviços incluídos saem de graça enquanto a assinatura estiver em dia.</p>
            <x-ui.button :href="route('booking.services')" icon="calendar-plus">Agendar</x-ui.button>
        </x-ui.card>
    @else
        <x-ui.card :title="$s->planName()">
            <dl class="summary-list">
                <div><dt>Situação</dt><dd>{{ $st?->label() }}</dd></div>
                <div><dt>Benefício hoje</dt><dd>{{ $benefitToday ? 'Sim' : 'Não' }}</dd></div>
                @if ($s->starts_on && $s->ends_on)
                    <div><dt>Período atual</dt><dd>{{ $s->starts_on->format('d/m/Y') }} a {{ $s->ends_on->format('d/m/Y') }}</dd></div>
                @endif
                @if ($s->ends_on)
                    <div><dt>Benefício até</dt><dd>{{ $s->ends_on->format('d/m/Y') }}</dd></div>
                @endif
                {{-- Proxima cobranca: so quando renova (ativa ou em atraso, sem cancelamento agendado). Data do Stripe quando houver. --}}
                @if (in_array($st, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) && ! $s->cancel_at_period_end && ($s->gateway_period_end_at || $s->ends_on))
                    <div data-next-charge><dt>Próxima cobrança</dt><dd>{{ $s->gateway_period_end_at ? BusinessTime::formatLocal($s->gateway_period_end_at, 'd/m/Y') : $s->ends_on->format('d/m/Y') }}@if ($s->planVersion) · <span class="numeric">{{ $s->planVersion->priceLabel() }}</span>@endif</dd></div>
                @endif
                @if ($s->planVersion)
                    <div><dt>Valor</dt><dd class="numeric">{{ $s->planVersion->priceLabel() }}</dd></div>
                    <div><dt>Incluído (sem limite)</dt><dd>{{ $s->planVersion->services->pluck('name')->join(', ') }}</dd></div>
                @endif
            </dl>
            @if ($st === SubscriptionStatus::PastDue)
                <p class="text-sm">O último pagamento não foi aprovado. O Stripe vai tentar de novo; se precisar, atualize o cartão pelo e-mail do Stripe. Seus benefícios valem até a data paga.</p>
            @elseif ($st === SubscriptionStatus::CancelScheduled)
                <p class="text-sm">Renovação cancelada: não haverá nova cobrança e seus benefícios seguem até {{ $s->ends_on?->format('d/m/Y') }}.</p>
            @endif

            @if ($st === SubscriptionStatus::Pending && $s->checkout_url && ($s->checkout_expires_at === null || $s->checkout_expires_at->isFuture()))
                <x-ui.button :href="$s->checkout_url" icon="external-link" variant="accent">Pagar assinatura no Stripe</x-ui.button>
                <p class="text-sm text-muted">Pagamento seguro no Stripe. Link válido até {{ $s->checkout_expires_at ? BusinessTime::formatLocal($s->checkout_expires_at) : 'amanhã' }}.</p>
            @endif

            <div class="cluster">
                @if (in_array($st, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true))
                    <x-ui.button variant="secondary" icon="x" data-dialog-open="cancelar-renovacao">Cancelar renovação</x-ui.button>
                @elseif ($st === SubscriptionStatus::CancelScheduled && $s->ends_on && $s->ends_on->toDateString() >= BusinessTime::today())
                    <form method="POST" action="{{ route('account.subscription.reactivate') }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="undo-2">Manter minha assinatura</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.card>

        @if (in_array($st, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true))
            <x-ui.confirm id="cancelar-renovacao" title="Cancelar a renovação?" :action="route('account.subscription.cancel')" confirm-label="Cancelar renovação">
                <p>Não haverá nova cobrança. Seus benefícios continuam até {{ $s->ends_on?->format('d/m/Y') ?? 'o fim do período pago' }}. Você pode desfazer até lá.</p>
            </x-ui.confirm>
        @endif
    @endif

    @if ($events->isNotEmpty())
        <x-ui.card title="Histórico da assinatura">
            <ul class="stack stack-sm" role="list" data-subscription-history>
                @foreach ($events as $ev)
                    <li class="cluster"><span class="text-sm text-muted numeric">{{ $ev->effective_at ? BusinessTime::formatLocal($ev->effective_at, 'd/m/Y') : '' }}</span> <span>{{ $ev->kind->label() }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card title="Pagamentos">
        @if ($payments->isEmpty())
            <x-ui.empty-state compact icon="receipt" title="Nenhum pagamento de assinatura." />
        @else
            <x-ui.table caption="Pagamentos da assinatura" caption-hidden stacked>
                <thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Valor</th><th scope="col">Reembolso</th></tr></thead>
                <tbody>
                    @foreach ($payments as $p)
                        <tr>
                            <td data-label="Data">{{ $p->paid_at ? BusinessTime::formatLocal($p->paid_at, 'd/m/Y') : '—' }}</td>
                            <td data-label="Tipo">{{ $p->kindLabel() }}</td>
                            <td data-label="Valor" class="numeric">{{ Money::fromCents($p->amount_cents)->format() }}</td>
                            <td data-label="Reembolso" class="numeric">{{ $p->refundedCents() > 0 ? Money::fromCents($p->refundedCents())->format() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.account>

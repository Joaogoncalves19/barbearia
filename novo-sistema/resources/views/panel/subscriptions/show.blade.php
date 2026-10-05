@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use App\Modules\Subscriptions\Enums\SubscriptionStatus;
    $st = $s->status;
    $variante = match ($st) {
        SubscriptionStatus::Active => 'success',
        SubscriptionStatus::PastDue, SubscriptionStatus::Pending => 'warning',
        SubscriptionStatus::CancelScheduled => 'info',
        default => 'neutral',
    };
    $podeCancelar = $st !== null && ! $st->isFinal();
    $podeReativar = $st === SubscriptionStatus::CancelScheduled && $s->ends_on !== null && $s->ends_on->toDateString() >= BusinessTime::today();
@endphp
<x-layouts.staff :title="'Assinatura de '.($s->customer->name ?? 'cliente')">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.subscriptions.index') }}">Voltar para assinaturas</a>
            <h1 class="page-head__title">{{ $s->customer->name ?? 'Cliente' }}</h1>
            <p><x-ui.badge :variant="$variante">{{ $st?->label() }}</x-ui.badge>
                <span class="text-muted">{{ $s->planName() }}{{ $s->planVersion ? ' · '.$s->planVersion->priceLabel().' (versão '.$s->planVersion->version.')' : '' }}</span></p>
        </div>
        <div class="cluster">
            @can('subscriptions.reactivate')
                @if ($podeReativar)
                    <form method="POST" action="{{ route('panel.subscriptions.reactivate', $s) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="undo-2">Reativar (desfazer cancelamento)</x-ui.button>
                    </form>
                @endif
            @endcan
            @can('subscriptions.cancel')
                @if ($podeCancelar)
                    <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-assinatura">Cancelar assinatura</x-ui.button>
                @endif
            @endcan
        </div>
    </header>

    @error('subscription')@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror
    @error('refund')@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror

    @if ($st === SubscriptionStatus::Pending && $s->checkout_url)
        <x-ui.card title="Link de pagamento">
            <div class="stack stack-sm">
                <p>Envie este link ao cliente (ele também aparece na conta dele). Vale até {{ $s->checkout_expires_at ? BusinessTime::formatLocal($s->checkout_expires_at) : '24 h depois de gerado' }}. A assinatura ativa quando o Stripe confirmar o pagamento.</p>
                <x-ui.input name="checkout_url" label="Link" :value="$s->checkout_url" readonly optional />
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Dados">
        <dl class="summary-list">
            <div><dt>Benefício hoje</dt><dd>{{ $benefitToday ? 'Sim' : 'Não' }}</dd></div>
            <div><dt>Benefício (pago) até</dt><dd>{{ $s->ends_on?->format('d/m/Y') ?? 'Sem pagamento confirmado' }}</dd></div>
            <div><dt>Serviços incluídos</dt><dd>{{ $s->planVersion?->services->pluck('name')->join(', ') ?: '—' }}</dd></div>
            <div><dt>Início</dt><dd>{{ $s->starts_on?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt>Origem</dt><dd>{{ $s->origin->label() }}@if ($s->signupAppointment) · agendamento {{ $s->signupAppointment->code }}@endif</dd></div>
            <div><dt>Pagamento</dt><dd>{{ $s->gateway->label() }}{{ $s->gateway_status ? ' · situação no Stripe: '.$s->gateway_status : '' }}</dd></div>
            @if ($s->gateway_subscription_id)
                <div><dt>ID no Stripe</dt><dd class="text-sm">{{ $s->gateway_subscription_id }}{{ $s->gateway_customer_id ? ' · cliente '.$s->gateway_customer_id : '' }}</dd></div>
            @endif
            @if ($s->cancel_requested_at || $s->cancelled_at)
                <div><dt>Cancelamento</dt><dd>
                    {{ $s->cancel_source?->label() ?? '—' }}{{ $s->cancel_requested_at ? ' em '.BusinessTime::formatLocal($s->cancel_requested_at) : '' }}{{ $s->cancel_reason ? ': '.$s->cancel_reason : '' }}
                    @if ($s->cancel_effective_on)<br>Efetivo em {{ $s->cancel_effective_on->format('d/m/Y') }}@elseif ($s->cancel_at_period_end)<br>No fim do período pago@endif
                </dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($canSeePayments)
        <x-ui.card title="Pagamentos">
            @if ($payments->isEmpty())
                <x-ui.empty-state title="Nenhum pagamento" icon="wallet">Nenhum pagamento confirmado ainda.</x-ui.empty-state>
            @else
                <x-ui.table caption="Pagamentos da assinatura" caption-hidden stacked>
                    <thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Período</th><th scope="col">Valor</th><th scope="col">Reembolsado</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($payments as $p)
                            <tr>
                                <td data-label="Data">{{ $p->paid_at ? BusinessTime::formatLocal($p->paid_at, 'd/m/Y') : '—' }}</td>
                                <td data-label="Tipo">{{ $p->kindLabel() }}</td>
                                <td data-label="Período">{{ $p->period_start?->format('d/m') ?? '' }}{{ $p->period_end ? ' – '.$p->period_end->format('d/m/Y') : '—' }}</td>
                                <td data-label="Valor" class="numeric">{{ Money::fromCents($p->amount_cents)->format() }}</td>
                                <td data-label="Reembolsado" class="numeric">{{ Money::fromCents($p->refundedCents())->format() }}</td>
                                <td data-label="Ações">
                                    @can('subscriptions.refund')
                                        @if ($p->gateway === 'stripe' && $p->payment_intent_id && $p->refundableCents() > 0)
                                            <x-ui.button size="sm" variant="secondary" icon="undo-2" data-dialog-open="reembolso-{{ $p->id }}">Reembolsar</x-ui.button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @foreach ($p->refunds as $r)
                                <tr>
                                    <td data-label="Data">{{ $r->refunded_at ? BusinessTime::formatLocal($r->refunded_at, 'd/m/Y') : BusinessTime::formatLocal($r->created_at, 'd/m/Y') }}</td>
                                    <td data-label="Tipo">Reembolso ({{ $r->source === 'stripe' ? 'pelo Stripe' : 'pela equipe' }})</td>
                                    <td data-label="Período">{{ $r->reason }}</td>
                                    <td data-label="Valor" class="numeric">−{{ Money::fromCents($r->amount_cents)->format() }}</td>
                                    <td data-label="Reembolsado">{{ ['succeeded' => 'Concluído', 'pending' => 'Em processamento', 'failed' => 'Recusado'][$r->status] ?? $r->status }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </x-ui.table>
                <p class="text-sm text-muted">Pagamento de assinatura não entra no caixa, na comissão nem na gorjeta. Reembolso não encerra o benefício: se for o caso, cancele também.</p>
            @endif
        </x-ui.card>
    @endif

    @can('subscriptions.history')
        <x-ui.card title="Histórico">
            <ol class="timeline">
                @foreach ($s->events->sortByDesc('id') as $e)
                    <li>
                        <strong>{{ $e->kind->label() }}</strong>
                        <span class="text-sm text-muted">{{ $e->effective_at ? BusinessTime::formatLocal($e->effective_at) : '' }} · {{ $e->source->label() }}{{ $e->actor_user_id && isset($actors[$e->actor_user_id]) ? ' ('.$actors[$e->actor_user_id].')' : '' }}</span>
                        @if ($e->from_status !== $e->to_status && $e->to_status)<br><span class="text-sm">{{ $e->from_status?->label() ?? 'Início' }} → {{ $e->to_status->label() }}</span>@endif
                        @if ($e->reason)<br><span class="text-sm">{{ $e->reason }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    @endcan

    @can('subscriptions.cancel')
        @if ($podeCancelar)
            <x-ui.modal id="cancelar-assinatura" title="Cancelar esta assinatura?">
                <form method="POST" action="{{ route('panel.subscriptions.cancel', $s) }}" class="stack" id="form-cancelar-assinatura" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="cancelar-assinatura">
                    @error('subscription')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                    <fieldset class="check-group stack stack-sm">
                        <legend>Quando</legend>
                        @if ($st !== SubscriptionStatus::CancelScheduled && $st !== SubscriptionStatus::Pending)
                            <x-ui.radio name="mode" value="end" label="No fim do período pago" :hint="'Não cobra de novo; benefício até '.($s->ends_on?->format('d/m/Y') ?? 'o fim do período').'.'" :checked="old('mode', 'end') === 'end'" />
                        @endif
                        <x-ui.radio name="mode" value="now" label="Imediatamente" hint="Encerra agora; o benefício termina hoje. Não devolve dinheiro (use o reembolso, se for o caso)." :checked="old('mode') === 'now' || $st === SubscriptionStatus::CancelScheduled || $st === SubscriptionStatus::Pending" />
                    </fieldset>
                    <x-ui.input name="reason" id="motivo-cancelar-assinatura" label="Motivo" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-cancelar-assinatura">Cancelar assinatura</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endcan

    @can('subscriptions.refund')
        @foreach ($payments as $p)
            @if ($p->gateway === 'stripe' && $p->payment_intent_id && $p->refundableCents() > 0)
                <x-ui.modal :id="'reembolso-'.$p->id" title="Reembolsar pagamento">
                    <form method="POST" action="{{ route('panel.subscriptions.refund', $p) }}" class="stack" id="form-reembolso-{{ $p->id }}" novalidate>
                        @csrf
                        <input type="hidden" name="_dialog" value="reembolso-{{ $p->id }}">
                        <input type="hidden" name="request_key" value="{{ (string) Str::uuid() }}">
                        @error('refund')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                        <p>Pagamento de {{ Money::fromCents($p->amount_cents)->format() }} em {{ $p->paid_at ? BusinessTime::formatLocal($p->paid_at, 'd/m/Y') : '—' }}. Disponível para reembolso: {{ Money::fromCents($p->refundableCents())->format() }}. O dinheiro volta pelo Stripe para o cartão do cliente.</p>
                        <x-ui.input name="amount" :id="'valor-reembolso-'.$p->id" label="Valor" inputmode="decimal" :value="number_format($p->refundableCents() / 100, 2, ',', '.')" />
                        <x-ui.input name="reason" :id="'motivo-reembolso-'.$p->id" label="Motivo" />
                    </form>
                    <x-slot:footer>
                        <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                        <button type="submit" class="btn btn--danger" form="form-reembolso-{{ $p->id }}">Reembolsar</button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
        @endforeach
    @endcan
</x-layouts.staff>

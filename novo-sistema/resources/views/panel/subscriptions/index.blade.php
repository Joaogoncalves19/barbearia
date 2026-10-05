@php
    use App\Modules\Shared\Support\Money;
    use App\Modules\Subscriptions\Enums\SubscriptionStatus;
    $variante = fn (?SubscriptionStatus $s) => match ($s) {
        SubscriptionStatus::Active => 'success',
        SubscriptionStatus::PastDue, SubscriptionStatus::Pending => 'warning',
        SubscriptionStatus::CancelScheduled => 'info',
        default => 'neutral',
    };
    $filtros = ['vigentes' => 'Vigentes', 'todas' => 'Todas'] + collect(SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
<x-layouts.staff title="Assinaturas">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Assinaturas</h1>
            <p class="text-muted">Pagamento mensal pelo Stripe, separado do caixa, da comissão e da gorjeta. O benefício vale até a data paga; a situação mostra a cobrança.</p>
        </div>
        <div class="cluster">
            @can('subscriptions.create')
                <x-ui.button :href="route('panel.subscriptions.link')" icon="external-link">Gerar link de assinatura</x-ui.button>
            @endcan
            @can('plans.manage')
                <x-ui.button :href="route('panel.plans.index')" variant="secondary" icon="badge-check">Planos</x-ui.button>
            @endcan
            @can('subscriptions.history')
                <x-ui.button :href="route('panel.subscriptions.events')" variant="secondary" icon="history">Eventos do Stripe</x-ui.button>
            @endcan
        </div>
    </header>

    @unless ($stripeReady)
        <x-ui.alert variant="warning">Pagamento online não configurado neste ambiente: a adesão online não aparece para os clientes. As assinaturas existentes continuam valendo pela data paga.</x-ui.alert>
    @endunless

    <div class="dashboard-grid">
        <x-ui.card title="Com benefício hoje"><p class="h2 numeric">{{ $withBenefit }}</p></x-ui.card>
        <x-ui.card title="Receita mensal recorrente (MRR)"><p class="h2 numeric">{{ Money::fromCents($mrr)->format() }}</p><p class="text-sm text-muted">Ativas e em atraso, pelo preço contratado. Cancelamento agendado não entra.</p></x-ui.card>
        <x-ui.card title="Situação">
            <dl class="summary-list">
                @foreach (SubscriptionStatus::cases() as $st)
                    <div><dt>{{ $st->label() }}</dt><dd class="numeric">{{ $counts[$st->value] ?? 0 }}</dd></div>
                @endforeach
            </dl>
        </x-ui.card>
    </div>

    <form method="GET" action="{{ route('panel.subscriptions.index') }}" class="cluster" role="search">
        <x-ui.input name="busca" label="Cliente (nome ou e-mail)" :value="$search" optional />
        <x-ui.select name="situacao" label="Mostrar" :options="$filtros" :value="$filter" />
        <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
    </form>

    <x-ui.card title="Lista de assinaturas">
        @if ($subscriptions->isEmpty())
            <x-ui.empty-state title="Nenhuma assinatura" icon="badge-check">Nenhuma assinatura encontrada com este filtro.</x-ui.empty-state>
        @else
            <x-ui.table caption="Assinaturas" caption-hidden stacked>
                <thead><tr><th scope="col">Cliente</th><th scope="col">Plano</th><th scope="col">Situação</th><th scope="col">Benefício até</th><th scope="col">Origem</th></tr></thead>
                <tbody>
                    @foreach ($subscriptions as $s)
                        <tr>
                            <td data-label="Cliente"><a href="{{ route('panel.subscriptions.show', $s) }}">{{ $s->customer->name ?? 'Cliente' }}</a></td>
                            <td data-label="Plano">{{ $s->planName() }}@if ($s->planVersion) <span class="text-sm text-muted">· {{ $s->planVersion->priceLabel() }}</span>@endif</td>
                            <td data-label="Situação"><x-ui.badge :variant="$variante($s->status)">{{ $s->status?->label() }}</x-ui.badge></td>
                            <td data-label="Benefício até" class="numeric">{{ $s->ends_on?->format('d/m/Y') ?? '—' }}</td>
                            <td data-label="Origem">{{ $s->origin->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

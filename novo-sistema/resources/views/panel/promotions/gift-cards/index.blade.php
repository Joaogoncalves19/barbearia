@php use App\Modules\Shared\Support\Money; @endphp
<x-layouts.staff title="Vales-presente">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Vales-presente</h1>
            <p class="text-muted">O vale é uma forma de pagamento: o dinheiro entra no caixa na venda e, no atendimento, o vale paga de uma vez até o seu valor (uso único).</p>
        </div>
        @can('gift_cards.sell')
            <x-ui.button :href="route('panel.gift-cards.create')" icon="plus">Vender vale-presente</x-ui.button>
        @endcan
    </header>

    <form method="GET" action="{{ route('panel.gift-cards.index') }}" class="cluster" role="search">
        <x-ui.input name="codigo" label="Buscar pelo código" :value="$search" optional />
        <x-ui.select name="situacao" label="Mostrar" :options="['disponiveis' => 'Disponíveis', 'todos' => 'Todos']" :value="$filter" />
        <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
    </form>

    <x-ui.card title="Vales">
        @if ($cards->isEmpty())
            <x-ui.empty-state title="Nenhum vale" icon="receipt">Nenhum vale-presente encontrado.</x-ui.empty-state>
        @else
            <x-ui.table caption="Vales-presente" caption-hidden stacked>
                <thead><tr><th scope="col">Código</th><th scope="col">Valor</th><th scope="col">Para</th><th scope="col">Validade</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($cards as $g)
                        <tr>
                            <td data-label="Código"><a href="{{ route('panel.gift-cards.show', $g) }}">{{ $g->code }}</a>@if ($g->is_legacy) <span class="text-sm text-muted">(sistema antigo)</span>@endif</td>
                            <td data-label="Valor" class="numeric">{{ Money::fromCents($g->amount_cents)->format() }}</td>
                            <td data-label="Para">{{ $g->recipient_name ?? $g->purchaser_name ?? '—' }}</td>
                            <td data-label="Validade" class="numeric">{{ $g->expires_on?->format('d/m/Y') ?? 'Sem validade' }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$g->isUsable() ? 'success' : 'neutral'">{{ $g->situationLabel() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

<x-layouts.staff title="Pontos de clientes">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Pontos de clientes</h1>
            <p class="text-muted">Busque o cliente para ver saldo, pontos disponíveis, extrato e, com permissão, ajustar.</p>
        </div>
        @can('promotions.configure')
            <x-ui.button :href="route('panel.promotions.settings')" variant="secondary" icon="settings">Regras</x-ui.button>
        @endcan
    </header>

    <form method="GET" action="{{ route('panel.loyalty.customers') }}" class="cluster" role="search">
        <x-ui.input name="busca" label="Nome, e-mail ou telefone" :value="$term" />
        <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
    </form>

    @if ($term !== '')
        <x-ui.card title="Resultado">
            @if ($customers->isEmpty())
                <p class="text-sm text-muted">Nenhum cliente encontrado.</p>
            @else
                <x-ui.table caption="Clientes" caption-hidden stacked>
                    <thead><tr><th scope="col">Cliente</th><th scope="col">Contato</th><th scope="col">Saldo</th></tr></thead>
                    <tbody>
                        @foreach ($customers as $linha)
                            <tr>
                                <td data-label="Cliente"><a href="{{ route('panel.loyalty.customer', $linha['customer']->public_id) }}">{{ $linha['customer']->name }}</a></td>
                                <td data-label="Contato">{{ $linha['customer']->email ?? $linha['customer']->phone ?? '—' }}</td>
                                <td data-label="Saldo" class="numeric">{{ $linha['balance'] }} pontos</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
</x-layouts.staff>

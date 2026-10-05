<x-layouts.staff title="Planos de assinatura">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Planos de assinatura</h1>
            <p class="text-muted">Os serviços incluídos saem de graça para o assinante, sem limite de uso. Mudar o preço ou os serviços cria uma versão nova: quem já assina continua na versão contratada.</p>
        </div>
        <x-ui.button :href="route('panel.plans.create')" icon="plus">Novo plano</x-ui.button>
    </header>

    <x-ui.card title="Planos">
        @if ($plans->isEmpty())
            <x-ui.empty-state title="Nenhum plano" icon="badge-check">Crie o primeiro plano para oferecer a assinatura.</x-ui.empty-state>
        @else
            <x-ui.table caption="Planos de assinatura" caption-hidden stacked>
                <thead><tr><th scope="col">Plano</th><th scope="col">Preço</th><th scope="col">Serviços incluídos</th><th scope="col">Versão</th><th scope="col">Assinantes</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($plans as $p)
                        <tr>
                            <td data-label="Plano"><a href="{{ route('panel.plans.edit', $p) }}">{{ $p->name }}</a></td>
                            <td data-label="Preço" class="numeric">{{ $p->currentVersion?->priceLabel() ?? '—' }}</td>
                            <td data-label="Serviços incluídos">{{ $p->currentVersion?->services->pluck('name')->join(', ') ?: '—' }}</td>
                            <td data-label="Versão" class="numeric">{{ $p->currentVersion?->version ?? '—' }}</td>
                            <td data-label="Assinantes" class="numeric">{{ $subscribers[$p->id] ?? 0 }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$p->is_active ? 'success' : 'neutral'">{{ $p->is_active ? 'Aberto' : 'Fechado para adesões' }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

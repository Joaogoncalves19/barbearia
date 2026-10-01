@php
    use App\Modules\Shared\Support\Money;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
@endphp
<x-layouts.staff title="Comissões">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Comissões e repasses</h1>
            <p class="text-muted">Valores em aberto (ainda não repassados) de cada profissional. Comissão e gorjeta são separadas; vales são abatidos no repasse.</p>
        </div>
        <div class="cluster">
            @can('commissions.configure')
                <x-ui.button :href="route('panel.commission-rules.index')" variant="secondary" icon="settings">Regras de comissão</x-ui.button>
            @endcan
            @can('commissions.history')
                <x-ui.button :href="route('panel.commissions.history')" variant="secondary" icon="history">Histórico</x-ui.button>
            @endcan
            @can('payouts.view')
                <x-ui.button :href="route('panel.payouts.index')" variant="secondary" icon="banknote">Repasses</x-ui.button>
            @endcan
        </div>
    </header>

    <div class="stats">
        <div class="stat"><span class="stat__label">Comissão em aberto</span><span class="stat__value numeric">{{ $fmt($totals['commission']) }}</span></div>
        <div class="stat"><span class="stat__label">Gorjeta em aberto</span><span class="stat__value numeric">{{ $fmt($totals['tips']) }}</span></div>
        <div class="stat"><span class="stat__label">Vales a abater</span><span class="stat__value numeric">{{ $fmt($totals['advances']) }}</span></div>
        <div class="stat"><span class="stat__label">Líquido a repassar</span><span class="stat__value numeric">{{ $fmt($totals['net']) }}</span><span class="stat__foot">comissão + gorjeta − vales</span></div>
    </div>

    <x-ui.card title="Por profissional">
        @if ($rows->isEmpty())
            <x-ui.empty-state title="Nenhum profissional" icon="users">Cadastre profissionais para acompanhar comissões.</x-ui.empty-state>
        @else
            <x-ui.table caption="Saldo em aberto por profissional" caption-hidden stacked>
                <thead><tr><th scope="col">Profissional</th><th scope="col">Comissão</th><th scope="col">Gorjeta</th><th scope="col">Vales</th><th scope="col">Líquido</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php $p = $r['professional']; $o = $r['open']; @endphp
                        <tr>
                            <td data-label="Profissional"><a href="{{ route('panel.commissions.show', $p) }}">{{ $p->display_name }}</a>@if (! $p->is_active) <x-ui.badge>Inativo</x-ui.badge>@endif</td>
                            <td data-label="Comissão" class="numeric">{{ $fmt($o['commission']) }}</td>
                            <td data-label="Gorjeta" class="numeric">{{ $fmt($o['tips']) }}</td>
                            <td data-label="Vales" class="numeric">{{ $o['advances'] > 0 ? '−'.$fmt($o['advances']) : $fmt($o['advances']) }}</td>
                            <td data-label="Líquido" class="numeric"><strong>{{ $fmt($o['net']) }}</strong></td>
                            <td>
                                <div class="cluster">
                                    <x-ui.button :href="route('panel.commissions.show', $p)" variant="secondary" size="sm">Extrato<span class="visually-hidden"> de {{ $p->display_name }}</span></x-ui.button>
                                    @can('payouts.create')
                                        @if ($o['commission'] !== 0 || $o['tips'] !== 0 || $o['advances'] !== 0)
                                            <x-ui.button :href="route('panel.payouts.create', $p)" size="sm" icon="banknote">Repassar<span class="visually-hidden"> a {{ $p->display_name }}</span></x-ui.button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

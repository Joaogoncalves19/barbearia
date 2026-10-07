@php
    use App\Modules\Shared\Support\Money;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
@endphp
<x-layouts.staff title="Repasses">
    <header class="page-head">
        <div class="stack stack-sm">
            @can('commissions.view')
                <a class="link-arrow text-sm" href="{{ route('panel.commissions.index') }}">Voltar para comissões</a>
            @endcan
            <h1 class="page-head__title">Repasses</h1>
            <p class="text-muted">Pagamentos feitos aos profissionais: comissão + gorjeta − vales.</p>
        </div>
    </header>

    <form method="GET" action="{{ route('panel.payouts.index') }}" class="cluster" role="search">
        <x-ui.select name="profissional" label="Profissional" :options="$professionals->pluck('display_name', 'id')->all()" :value="$selected" placeholder="Todos" optional />
        <x-ui.button type="submit" variant="secondary" icon="funnel">Filtrar</x-ui.button>
    </form>

    <x-ui.card title="Repasses">
        @if ($payouts->isEmpty())
            <x-ui.empty-state compact icon="banknote" title="Nenhum repasse." />
        @else
            <x-ui.table caption="Repasses" caption-hidden stacked>
                <thead><tr><th scope="col">Repasse</th><th scope="col">Profissional</th><th scope="col">Pago em</th><th scope="col">Forma</th><th scope="col">Comissão</th><th scope="col">Gorjeta</th><th scope="col">Vales</th><th scope="col">Líquido</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($payouts as $p)
                        <tr>
                            <td data-label="Repasse"><a href="{{ route('panel.payouts.show', $p) }}">#{{ $p->id }}</a></td>
                            <td data-label="Profissional">{{ $p->professional->display_name ?? '—' }}</td>
                            <td data-label="Pago em" class="numeric">{{ $p->paid_on?->format('d/m/Y') ?? '—' }}</td>
                            <td data-label="Forma">{{ $p->method?->label() ?? '—' }}</td>
                            <td data-label="Comissão" class="numeric">{{ $p->commission_cents !== null ? $fmt($p->commission_cents) : '—' }}</td>
                            <td data-label="Gorjeta" class="numeric">{{ $fmt((int) $p->tip_cents) }}</td>
                            <td data-label="Vales" class="numeric">{{ $p->advances_cents !== null ? '−'.$fmt($p->advances_cents) : '—' }}</td>
                            <td data-label="Líquido" class="numeric"><strong>{{ $fmt($p->amount_cents) }}</strong></td>
                            <td data-label="Situação">@if ($p->isReversed())<x-ui.badge variant="danger">Estornado</x-ui.badge>@elseif ($p->isLegacy())<x-ui.badge>Sistema antigo</x-ui.badge>@else<x-ui.badge variant="success">Pago</x-ui.badge>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

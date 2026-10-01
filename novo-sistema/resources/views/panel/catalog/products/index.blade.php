@php
    use App\Modules\Catalog\Services\StockLedger;
    use App\Modules\Shared\Support\Money;
    $situacoes = ['ok' => ['success', 'Em estoque'], 'low' => ['warning', 'Estoque baixo'], 'out' => ['danger', 'Sem estoque']];
@endphp
<x-layouts.staff title="Produtos e estoque">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Produtos e estoque</h1>
            <p class="text-muted">O saldo é a soma das movimentações: muda só por entrada, saída, venda, consumo, ajuste de inventário ou estorno.</p>
        </div>
        @can('products.create')
            <x-ui.button :href="route('panel.products.create')" icon="plus">Novo produto</x-ui.button>
        @endcan
    </header>

    <nav class="segmented" aria-label="Filtrar produtos">
        @foreach (['ativos' => 'Ativos', 'baixo' => 'Estoque baixo', 'inativos' => 'Inativos', 'todos' => 'Todos'] as $valor => $rotulo)
            <a class="segmented__item" href="{{ route('panel.products.index', ['situacao' => $valor]) }}" @if ($filter === $valor) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>

    @error('product')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($products->isEmpty())
        <x-ui.empty-state title="Nenhum produto neste filtro" icon="package">
            @if ($filter === 'ativos') Cadastre o primeiro produto. @else Nada para mostrar aqui. @endif
        </x-ui.empty-state>
    @else
        <x-ui.table caption="Produtos" caption-hidden stacked>
            <thead>
                <tr>
                    <th scope="col">Produto</th>
                    <th scope="col">Preço de venda</th>
                    <th scope="col">Custo</th>
                    <th scope="col">Saldo</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="visually-hidden">Ações</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $p)
                    @php $saldo = $balances[$p->id] ?? 0; [$cor, $rotulo] = $situacoes[StockLedger::situation($p, $saldo)]; @endphp
                    <tr>
                        <td data-label="Produto">
                            <strong>{{ $p->name }}</strong>
                            @if ($p->sku)<span class="text-sm text-muted"> · {{ $p->sku }}</span>@endif
                            @unless ($p->isForSale())<span class="text-sm text-muted"> · só consumo</span>@endunless
                        </td>
                        <td data-label="Preço de venda" class="numeric">{{ $p->price_cents !== null ? Money::fromCents($p->price_cents)->format() : '—' }}</td>
                        <td data-label="Custo" class="numeric">{{ $p->cost_cents !== null ? Money::fromCents($p->cost_cents)->format() : '—' }}</td>
                        <td data-label="Saldo" class="numeric">{{ $saldo }} {{ $p->unit }}@if ($p->min_stock !== null)<span class="text-sm text-muted"> (mín. {{ $p->min_stock }})</span>@endif</td>
                        <td data-label="Situação">
                            @if ($p->is_active)<x-ui.badge :variant="$cor">{{ $rotulo }}</x-ui.badge>@else<x-ui.badge>Inativo</x-ui.badge>@endif
                        </td>
                        <td>
                            <div class="row-actions">
                                @can('stock.view')
                                    <x-ui.button :href="route('panel.stock.show', $p)" variant="ghost" size="sm" icon="boxes">Estoque<span class="visually-hidden"> de {{ $p->name }}</span></x-ui.button>
                                @endcan
                                @can('products.update')
                                    <x-ui.button :href="route('panel.products.edit', $p)" variant="ghost" size="sm" icon="pencil">Editar<span class="visually-hidden"> {{ $p->name }}</span></x-ui.button>
                                @endcan
                                @can('products.toggle')
                                    @include('panel.partials.status-toggle', [
                                        'route' => 'panel.products.status', 'model' => $p, 'active' => $p->is_active, 'label' => $p->name,
                                        'id' => 'desativar-produto-'.$p->id,
                                        'effect' => 'O produto deixa de poder ser vendido ou usado em atendimentos novos.',
                                    ])
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</x-layouts.staff>

@php
    use App\Modules\Catalog\Enums\StockMovementKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;
    $situacoes = ['ok' => ['success', 'Em estoque'], 'low' => ['warning', 'Estoque baixo'], 'out' => ['danger', 'Sem estoque']];
    [$cor, $rotulo] = $situacoes[$situation];
@endphp
<x-layouts.staff :title="'Estoque: '.$product->name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.products.index') }}">Voltar para produtos</a>
            <h1 class="page-head__title">{{ $product->name }}</h1>
            <p>
                @if ($product->is_active)<x-ui.badge :variant="$cor">{{ $rotulo }}</x-ui.badge>@else<x-ui.badge>Inativo</x-ui.badge>@endif
                <span class="text-muted">Saldo: <strong data-balance>{{ $balance }}</strong> {{ $product->unitLabel() }}@if ($product->min_stock !== null) · mínimo {{ $product->min_stock }}@endif</span>
            </p>
        </div>
    </header>

    @error('stock')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <div class="dashboard-grid">
        @can('stock.receive')
            <x-ui.card title="Entrada">
                <form method="POST" action="{{ route('panel.stock.receive', $product) }}" class="stack stack-sm" novalidate>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                    <x-ui.input name="quantity" id="entrada-quantidade" label="Quantidade" type="number" min="1" inputmode="numeric" />
                    <x-ui.input name="unit_cost" id="entrada-custo" label="Custo unitário" inputmode="decimal" optional />
                    <x-ui.input name="reason" id="entrada-obs" label="Observação" hint="Ex.: nota fiscal, fornecedor." optional />
                    <div><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Registrar entrada</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan
        @can('stock.issue')
            <x-ui.card title="Saída ou perda">
                <form method="POST" action="{{ route('panel.stock.issue', $product) }}" class="stack stack-sm" novalidate>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                    <x-ui.select name="kind" id="saida-tipo" label="Tipo" :options="[StockMovementKind::Usage->value => 'Saída (uso interno)', StockMovementKind::Loss->value => 'Perda (quebra, validade)']" />
                    <x-ui.input name="quantity" id="saida-quantidade" label="Quantidade" type="number" min="1" inputmode="numeric" />
                    <x-ui.input name="reason" id="saida-motivo" label="Motivo" />
                    <div><x-ui.button type="submit" variant="secondary" size="sm">Registrar saída</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan
        @can('stock.adjust')
            <x-ui.card title="Ajuste de inventário">
                <form method="POST" action="{{ route('panel.stock.adjust', $product) }}" class="stack stack-sm" novalidate>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                    <x-ui.input name="counted" id="ajuste-contagem" label="Contagem física" type="number" min="0" inputmode="numeric" hint="O sistema lança a diferença para o saldo atual." />
                    <x-ui.input name="reason" id="ajuste-motivo" label="Motivo" />
                    <div><x-ui.button type="submit" variant="secondary" size="sm">Ajustar</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan
    </div>

    <x-ui.card title="Movimentações">
        @if ($movements->isEmpty())
            <p class="text-sm text-muted">Nenhuma movimentação.</p>
        @else
            <x-ui.table caption="Movimentações de estoque" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Quantidade</th><th scope="col">Saldo depois</th><th scope="col">Origem / motivo</th><th scope="col">Quem</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $m->occurred_at ? BusinessTime::formatLocal($m->occurred_at, 'd/m/Y H:i') : '—' }}</td>
                            <td data-label="Tipo">{{ $m->kind->label() }}</td>
                            <td data-label="Quantidade" class="numeric">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                            <td data-label="Saldo depois" class="numeric">{{ $m->balance_after ?? '—' }}</td>
                            <td data-label="Origem / motivo">
                                @if ($m->attendance)<a href="{{ route('panel.attendances.show', $m->attendance) }}">{{ $m->attendance->code }}</a>@endif
                                {{ $m->reason }}
                                @if ($m->unit_cost_cents !== null)<span class="text-sm text-muted"> · custo {{ Money::fromCents($m->unit_cost_cents)->format() }}</span>@endif
                            </td>
                            <td data-label="Quem">{{ $m->createdBy->name ?? $m->actor_label ?? '—' }}</td>
                            <td>
                                @if ($m->reversal !== null)
                                    <x-ui.badge>Estornada</x-ui.badge>
                                @elseif ($m->kind->isReversible() && $m->attendance_id === null)
                                    @can('stock.adjust')
                                        <x-ui.button variant="ghost" size="sm" icon="undo-2" data-dialog-open="estornar-mov-{{ $m->id }}">Estornar<span class="visually-hidden"> {{ $m->kind->label() }} de {{ abs($m->quantity) }}</span></x-ui.button>
                                        <x-ui.modal :id="'estornar-mov-'.$m->id" title="Estornar movimentação">
                                            <form method="POST" action="{{ route('panel.stock.reverse', [$product, $m]) }}" class="stack" id="form-estornar-mov-{{ $m->id }}" novalidate>
                                                @csrf
                                                <input type="hidden" name="_dialog" value="estornar-mov-{{ $m->id }}">
                                                <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                                                <p>Lança a quantidade inversa ({{ -$m->quantity > 0 ? '+' : '' }}{{ -$m->quantity }}) apontando esta movimentação. A original continua no histórico.</p>
                                                <x-ui.input name="reason" :id="'motivo-estornar-'.$m->id" label="Motivo" />
                                            </form>
                                            <x-slot:footer>
                                                <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                                                <button type="submit" class="btn btn--danger" form="form-estornar-mov-{{ $m->id }}">Confirmar estorno</button>
                                            </x-slot:footer>
                                        </x-ui.modal>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $movements->links() }}
        @endif
    </x-ui.card>
</x-layouts.staff>

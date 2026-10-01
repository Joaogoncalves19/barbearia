@php
    use App\Modules\Catalog\Models\Product;
    use App\Modules\Shared\Support\Money;
    $novo = ! $product->exists;
@endphp
<x-layouts.staff :title="$novo ? 'Novo produto' : 'Editar produto'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.products.index') }}">Voltar para produtos</a>
            <h1 class="page-head__title">{{ $novo ? 'Novo produto' : $product->name }}</h1>
            @unless ($novo)
                <p class="text-muted"><x-ui.badge :variant="$product->is_active ? 'success' : 'neutral'">{{ $product->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge> O saldo não é editado aqui: use a tela de estoque.</p>
            @endunless
        </div>
    </header>

    <form method="POST" action="{{ $novo ? route('panel.products.store') : route('panel.products.update', $product) }}" class="stack" novalidate>
        @csrf
        @unless ($novo)
            @method('PUT')
            <input type="hidden" name="version" value="{{ $product->lock_version }}">
        @endunless
        @error('version')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

        <div class="dashboard-grid">
            <x-ui.card title="Produto">
                <div class="stack">
                    <x-ui.input name="name" label="Nome" :value="$product->name" autofocus />
                    <x-ui.input name="sku" label="Código" :value="$product->sku" hint="Opcional. Ex.: código de barras ou do fornecedor." optional />
                    <x-ui.select name="unit" label="Unidade" :options="Product::UNITS" :value="$product->unit" hint="O estoque é contado em números inteiros desta unidade." />
                    <x-ui.textarea name="description" label="Descrição" :value="$product->description" rows="2" optional />
                </div>
            </x-ui.card>
            <x-ui.card title="Valores e estoque mínimo">
                <div class="stack">
                    <x-ui.input name="price" label="Preço de venda" inputmode="decimal" :value="$product->price_cents !== null ? Money::fromCents($product->price_cents)->toInput() : null"
                        hint="Deixe em branco se o produto só é usado no serviço (não vendido). Vale para vendas novas." optional />
                    <x-ui.input name="cost" label="Custo unitário" inputmode="decimal" :value="$product->cost_cents !== null ? Money::fromCents($product->cost_cents)->toInput() : null" optional />
                    <x-ui.input name="min_stock" label="Estoque mínimo" type="number" min="0" inputmode="numeric" :value="$product->min_stock" hint="Abaixo disso o produto aparece em &quot;Estoque baixo&quot;." optional />
                </div>
            </x-ui.card>
        </div>
        <div><x-ui.button type="submit">{{ $novo ? 'Cadastrar produto' : 'Salvar alterações' }}</x-ui.button></div>
    </form>

    @if (! $novo && $canDelete)
        @can('products.toggle')
            <x-ui.card title="Excluir produto">
                <p class="text-sm">Este produto nunca teve movimentação, venda ou consumo, então pode ser excluído.</p>
                <x-ui.button variant="danger" icon="trash-2" data-dialog-open="excluir-produto">Excluir produto</x-ui.button>
                <x-ui.confirm id="excluir-produto" :title="'Excluir '.$product->name.'?'" :action="route('panel.products.destroy', $product)" method="DELETE" confirm-label="Excluir">
                    <p>O cadastro é removido de vez. Produtos com histórico só podem ser desativados.</p>
                </x-ui.confirm>
            </x-ui.card>
        @endcan
    @endif
</x-layouts.staff>

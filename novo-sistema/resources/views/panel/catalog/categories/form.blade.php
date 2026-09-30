@php $nova = ! $category->exists; @endphp
<x-layouts.staff :title="$nova ? 'Nova categoria' : 'Editar categoria'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.categories.index') }}">Voltar para categorias</a>
            <h1 class="page-head__title">{{ $nova ? 'Nova categoria' : $category->name }}</h1>
        </div>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ $nova ? route('panel.categories.store') : route('panel.categories.update', $category) }}" class="stack container-narrow" novalidate>
            @csrf
            @unless ($nova)
                @method('PUT')
                <input type="hidden" name="version" value="{{ $category->lock_version }}">
            @endunless
            @error('version')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

            <x-ui.input name="name" label="Nome" :value="$category->name" autofocus />
            <x-ui.textarea name="description" label="Descrição" :value="$category->description" rows="3" hint="Aparece no site, abaixo do nome da categoria." optional />
            <div><x-ui.button type="submit">{{ $nova ? 'Criar categoria' : 'Salvar' }}</x-ui.button></div>
        </form>
    </x-ui.card>

    @if (! $nova && ($canDelete ?? false))
        @can('delete', $category)
            <x-ui.card title="Excluir categoria">
                <p class="text-sm text-muted">Esta categoria nunca teve serviços, então pode ser excluída. Categorias com serviços só podem ser desativadas.</p>
                <div><x-ui.button variant="danger" icon="trash-2" data-dialog-open="excluir-categoria">Excluir categoria</x-ui.button></div>
                <x-ui.confirm id="excluir-categoria" :title="'Excluir '.$category->name.'?'" :action="route('panel.categories.destroy', $category)" method="DELETE" confirm-label="Excluir">
                    <p>A categoria sai do catálogo. Esta ação não pode ser desfeita pelo painel.</p>
                </x-ui.confirm>
            </x-ui.card>
        @endcan
    @endif
</x-layouts.staff>

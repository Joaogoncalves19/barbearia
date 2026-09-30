<x-layouts.staff title="Categorias">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Categorias</h1>
            <p class="text-muted">Organizam o catálogo na ordem em que aparecem para o cliente. Categoria inativa tira os serviços dela de novos agendamentos.</p>
        </div>
        @can('services.create')
            <x-ui.button :href="route('panel.categories.create')" icon="plus">Nova categoria</x-ui.button>
        @endcan
    </header>

    @if ($errors->has('category'))
        <x-ui.alert variant="danger">{{ $errors->first('category') }}</x-ui.alert>
    @endif

    @if ($categories->isEmpty())
        <x-ui.empty-state title="Nenhuma categoria ainda" icon="tag">
            Crie categorias como "Cabelo" e "Barba" para organizar os serviços.
        </x-ui.empty-state>
    @else
        <x-ui.table caption="Categorias" caption-hidden stacked>
            <thead>
                <tr>
                    <th scope="col">Categoria</th>
                    <th scope="col">Serviços</th>
                    <th scope="col">Situação</th>
                    @can('services.display')<th scope="col">Ordem</th>@endcan
                    <th scope="col"><span class="visually-hidden">Ações</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $c)
                    <tr>
                        <td data-label="Categoria"><strong>{{ $c->name }}</strong>@if ($c->description)<br><span class="text-sm text-muted">{{ $c->description }}</span>@endif</td>
                        <td data-label="Serviços">{{ $c->active_services_count }} ativo(s) de {{ $c->services_count }}</td>
                        <td data-label="Situação">
                            <x-ui.badge :variant="$c->is_active ? 'success' : 'neutral'">{{ $c->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge>
                        </td>
                        @can('services.display')
                            <td data-label="Ordem">@include('panel.partials.move', ['route' => 'panel.categories.move', 'model' => $c, 'label' => $c->name, 'first' => $loop->first, 'last' => $loop->last])</td>
                        @endcan
                        <td>
                            <div class="row-actions">
                                @can('services.update')
                                    <x-ui.button :href="route('panel.categories.edit', $c)" variant="ghost" size="sm" icon="pencil">Editar<span class="visually-hidden"> {{ $c->name }}</span></x-ui.button>
                                @endcan
                                @can('services.toggle')
                                    @include('panel.partials.status-toggle', [
                                        'route' => 'panel.categories.status', 'model' => $c, 'active' => $c->is_active, 'label' => $c->name,
                                        'id' => 'desativar-categoria-'.$c->id,
                                        'effect' => 'Os '.$c->active_services_count.' serviço(s) ativo(s) desta categoria deixam de aparecer para novos agendamentos e no site.',
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

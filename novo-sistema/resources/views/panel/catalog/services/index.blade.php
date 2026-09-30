<x-layouts.staff title="Serviços">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Serviços</h1>
            <p class="text-muted">Preço e duração atuais de cada serviço. Mudar o preço vale para agendamentos novos; os já feitos mantêm o valor registrado.</p>
        </div>
        @can('services.create')
            <x-ui.button :href="route('panel.services.create')" icon="plus">Novo serviço</x-ui.button>
        @endcan
    </header>

    <nav class="segmented" aria-label="Filtrar serviços">
        @foreach (['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'] as $valor => $rotulo)
            <a class="segmented__item" href="{{ route('panel.services.index', ['situacao' => $valor]) }}" @if ($filter === $valor) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>

    @if ($errors->has('service'))
        <x-ui.alert variant="danger">{{ $errors->first('service') }}</x-ui.alert>
    @endif

    @forelse ($groups as $g)
        <section class="stack stack-sm">
            <h2 class="h3">
                {{ $g['category']?->name ?? 'Sem categoria' }}
                @if ($g['category'] && ! $g['category']->is_active)<x-ui.badge>Categoria inativa</x-ui.badge>@endif
            </h2>
            <x-ui.table :caption="'Serviços: '.($g['category']?->name ?? 'sem categoria')" caption-hidden stacked>
                <thead>
                    <tr>
                        <th scope="col">Serviço</th>
                        <th scope="col">Preço atual</th>
                        <th scope="col">Duração</th>
                        <th scope="col">Profissionais</th>
                        <th scope="col">Situação</th>
                        @can('services.display')<th scope="col">Ordem</th>@endcan
                        <th scope="col"><span class="visually-hidden">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($g['services'] as $s)
                        <tr>
                            <td data-label="Serviço"><strong>{{ $s->name }}</strong></td>
                            <td data-label="Preço atual" class="numeric">{{ $s->price()->format() }}</td>
                            <td data-label="Duração">{{ $s->durationLabel() }}</td>
                            <td data-label="Profissionais">{{ $s->professionals_count }}</td>
                            <td data-label="Situação">
                                <x-ui.badge :variant="$s->is_active ? 'success' : 'neutral'">{{ $s->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                                @if ($s->is_active && ! $s->is_public)<x-ui.badge>Fora do site</x-ui.badge>@endif
                                @if ($s->is_featured)<x-ui.badge variant="accent">Destaque</x-ui.badge>@endif
                            </td>
                            @can('services.display')
                                <td data-label="Ordem">@include('panel.partials.move', ['route' => 'panel.services.move', 'model' => $s, 'label' => $s->name, 'first' => $loop->first, 'last' => $loop->last])</td>
                            @endcan
                            <td>
                                <div class="row-actions">
                                    @can('services.update')
                                        <x-ui.button :href="route('panel.services.edit', $s)" variant="ghost" size="sm" icon="pencil">Editar<span class="visually-hidden"> {{ $s->name }}</span></x-ui.button>
                                    @endcan
                                    @can('services.toggle')
                                        @include('panel.partials.status-toggle', [
                                            'route' => 'panel.services.status', 'model' => $s, 'active' => $s->is_active, 'label' => $s->name,
                                            'id' => 'desativar-servico-'.$s->id,
                                            'effect' => 'O serviço deixa de ser oferecido em novos agendamentos e sai do site.',
                                        ])
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </section>
    @empty
        <x-ui.empty-state title="Nenhum serviço {{ $filter === 'inativos' ? 'inativo' : ($filter === 'ativos' ? 'ativo' : '') }}" icon="scissors">
            @if ($filter === 'ativos') Cadastre o primeiro serviço do catálogo. @else Nada para mostrar neste filtro. @endif
        </x-ui.empty-state>
    @endforelse
</x-layouts.staff>

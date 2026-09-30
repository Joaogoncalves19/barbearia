<x-layouts.staff title="Profissionais">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Profissionais</h1>
            <p class="text-muted">Quem atende, quais serviços faz e como aparece no site. Desligar é desativar: o histórico continua intacto.</p>
        </div>
        @can('professionals.create')
            <x-ui.button :href="route('panel.professionals.create')" icon="plus">Novo profissional</x-ui.button>
        @endcan
    </header>

    <nav class="segmented" aria-label="Filtrar profissionais">
        @foreach (['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'] as $valor => $rotulo)
            <a class="segmented__item" href="{{ route('panel.professionals.index', ['situacao' => $valor]) }}" @if ($filter === $valor) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>

    @if ($professionals->isEmpty())
        <x-ui.empty-state title="Ninguém neste filtro" icon="users">
            @if ($filter === 'ativos') Cadastre o primeiro profissional da equipe. @else Nada para mostrar neste filtro. @endif
        </x-ui.empty-state>
    @else
        <x-ui.table caption="Profissionais" caption-hidden stacked>
            <thead>
                <tr>
                    <th scope="col">Profissional</th>
                    <th scope="col">Acesso ao sistema</th>
                    <th scope="col">Serviços</th>
                    <th scope="col">Situação</th>
                    @can('professionals.display')<th scope="col">Ordem</th>@endcan
                    <th scope="col"><span class="visually-hidden">Ações</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($professionals as $p)
                    <tr>
                        <td data-label="Profissional">
                            <span class="cluster">
                                <x-ui.avatar :name="$p->display_name" :src="$p->photoUrl()" size="sm" />
                                <a href="{{ route('panel.professionals.show', $p) }}"><strong>{{ $p->display_name }}</strong></a>
                            </span>
                        </td>
                        <td data-label="Acesso ao sistema">{{ $p->user?->loginLabel() ?? 'Sem login' }}</td>
                        <td data-label="Serviços">{{ $p->services_count }}</td>
                        <td data-label="Situação">
                            <x-ui.badge :variant="$p->is_active ? 'success' : 'neutral'">{{ $p->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge>
                            @if ($p->is_active && ! $p->is_bookable)<x-ui.badge variant="warning">Não recebe agendamentos</x-ui.badge>@endif
                            @if ($p->is_active && ! $p->is_public)<x-ui.badge>Fora do site</x-ui.badge>@endif
                            @if ($p->is_featured)<x-ui.badge variant="accent">Destaque</x-ui.badge>@endif
                        </td>
                        @can('professionals.display')
                            <td data-label="Ordem">@include('panel.partials.move', ['route' => 'panel.professionals.move', 'model' => $p, 'label' => $p->display_name, 'first' => $loop->first, 'last' => $loop->last])</td>
                        @endcan
                        <td>
                            <div class="row-actions">
                                @can('professionals.update')
                                    <x-ui.button :href="route('panel.professionals.edit', $p)" variant="ghost" size="sm" icon="pencil">Editar<span class="visually-hidden"> {{ $p->display_name }}</span></x-ui.button>
                                @endcan
                                @can('professionals.services')
                                    <x-ui.button :href="route('panel.professionals.services.edit', $p)" variant="ghost" size="sm" icon="scissors">Serviços<span class="visually-hidden"> de {{ $p->display_name }}</span></x-ui.button>
                                @endcan
                                @can('professionals.toggle')
                                    @include('panel.partials.status-toggle', [
                                        'route' => 'panel.professionals.status', 'model' => $p, 'active' => $p->is_active, 'label' => $p->display_name,
                                        'id' => 'desativar-profissional-'.$p->id,
                                        'effect' => $p->display_name.' deixa de receber novos agendamentos e sai do site. A conta de acesso ao sistema, se houver, é tratada à parte em Usuários.',
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

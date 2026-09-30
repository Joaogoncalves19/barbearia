<x-layouts.staff title="Usuários">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Usuários da equipe</h1>
            <p class="text-muted">Quem acessa o painel e o que cada papel pode fazer.</p>
        </div>
        @can('create', \App\Modules\Identity\Models\User::class)
            <x-ui.button :href="route('panel.users.create')" icon="plus">Novo usuário</x-ui.button>
        @endcan
    </header>

    <x-ui.table caption="Usuários da equipe" caption-hidden stacked>
        <thead>
            <tr>
                <th scope="col">Nome</th>
                <th scope="col">Usuário</th>
                <th scope="col">Papel</th>
                <th scope="col">Situação</th>
                <th scope="col">Último acesso</th>
                <th scope="col"><span class="visually-hidden">Ações</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $u)
                <tr>
                    <td data-label="Nome">{{ $u->name }}</td>
                    <td data-label="Usuário">{{ $u->username ?? '—' }}</td>
                    <td data-label="Papel">{{ $u->role?->label() }}</td>
                    <td data-label="Situação">
                        @if ($u->is_active)
                            <x-ui.badge variant="success">Ativo</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Sem acesso</x-ui.badge>
                        @endif
                        @if ($u->must_change_password)<x-ui.badge variant="warning">Senha provisória</x-ui.badge>@endif
                    </td>
                    <td data-label="Último acesso">{{ $u->last_login_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                    <td>
                        @can('update', $u)
                            <a href="{{ route('panel.users.edit', $u) }}">Editar<span class="visually-hidden"> {{ $u->name }}</span></a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    {{ $users->links() }}
</x-layouts.staff>

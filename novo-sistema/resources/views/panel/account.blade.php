<x-layouts.staff title="Minha conta">
    <header class="page-head">
        <h1 class="page-head__title">Minha conta</h1>
    </header>

    <div class="stack stack-lg">
        <x-ui.card title="Seus dados" class="account-data">
            <form method="POST" action="{{ route('panel.account.update') }}" class="stack" novalidate>
                @csrf
                @method('PUT')
                <x-ui.input name="name" label="Nome" autocomplete="name" :value="$user->name" />
                <dl class="summary-list">
                    <div><dt>Usuário</dt><dd>{{ $user->username ?? '—' }}</dd></div>
                    <div><dt>E-mail</dt><dd>{{ $user->email ?? 'Não cadastrado' }}</dd></div>
                    <div><dt>Papel</dt><dd>{{ $user->role?->label() }}</dd></div>
                    <div><dt>Último acesso</dt><dd>{{ $user->last_login_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i') ?? '—' }}</dd></div>
                </dl>
                <p class="text-sm text-muted">Usuário, e-mail e papel são definidos pelo proprietário.</p>
                <div class="cluster">
                    <x-ui.button type="submit">Salvar nome</x-ui.button>
                    <x-ui.button :href="route('panel.password.edit')" variant="secondary" icon="key-round">Trocar senha</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- A lista do proprietario passa de cem itens: fica recolhida e em colunas. --}}
        <x-ui.card title="O que seu perfil pode fazer">
            <p class="text-sm text-muted">{{ count($permissions) === 1 ? '1 permissão' : count($permissions).' permissões' }} do papel {{ $user->role?->label() }}.</p>
            <x-ui.hint summary="Ver a lista completa">
                <ul class="check-list" role="list">
                    @foreach ($permissions as $p)
                        <li><x-icon name="check" class="icon-sm" /> <span>{{ $p }}</span></li>
                    @endforeach
                </ul>
            </x-ui.hint>
        </x-ui.card>
    </div>
</x-layouts.staff>

<x-layouts.staff title="Trocar senha">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">{{ $mustChange ? 'Crie sua senha pessoal' : 'Trocar senha' }}</h1>
            <p class="text-muted">
                @if ($mustChange)
                    Você entrou com uma senha provisória. Crie uma senha que só você conheça para continuar.
                @else
                    Ao trocar a senha, os outros aparelhos conectados à sua conta saem automaticamente.
                @endif
            </p>
        </div>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('panel.password.update') }}" class="stack container-narrow" novalidate>
            @csrf
            @method('PUT')
            <x-ui.input name="current_password" :label="$mustChange ? 'Senha provisória' : 'Senha atual'" type="password" autocomplete="current-password" autofocus />
            <x-ui.input name="password" label="Nova senha" type="password" autocomplete="new-password" hint="Pelo menos 8 caracteres, com letras e números." />
            <x-ui.input name="password_confirmation" label="Repita a nova senha" type="password" autocomplete="new-password" />
            <div><x-ui.button type="submit">Salvar senha</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.staff>

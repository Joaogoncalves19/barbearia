<x-layouts.account title="Senha">
    <header class="stack stack-sm">
        <h1 class="h2">{{ $hasPassword ? 'Alterar senha' : 'Criar senha' }}</h1>
        <p class="text-muted">
            @if ($hasPassword)
                Ao trocar a senha, os outros aparelhos conectados à sua conta saem automaticamente.
            @else
                Você entra pelo link enviado ao e-mail. Se quiser, crie uma senha para entrar também com ela.
            @endif
        </p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.password.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            @if ($hasPassword)
                <x-ui.input name="current_password" label="Senha atual" type="password" autocomplete="current-password" />
            @endif
            <x-ui.input name="password" label="Nova senha" type="password" autocomplete="new-password" hint="Pelo menos 8 caracteres, com letras e números." />
            <x-ui.input name="password_confirmation" label="Repita a nova senha" type="password" autocomplete="new-password" />
            <div><x-ui.button type="submit" variant="accent">Salvar senha</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.account>

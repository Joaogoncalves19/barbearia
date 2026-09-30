<x-layouts.auth title="Criar nova senha" area="site">
    <div class="stack stack-sm">
        <h1 class="h2">Criar nova senha</h1>
        <p class="text-muted">Confirme o e-mail da sua conta e escolha a nova senha.</p>
    </div>

    <form method="POST" action="{{ route('customer.password.update') }}" class="stack" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="username" autofocus />
        <x-ui.input name="password" label="Nova senha" type="password" autocomplete="new-password" hint="Pelo menos 8 caracteres, com letras e números." />
        <x-ui.input name="password_confirmation" label="Repita a nova senha" type="password" autocomplete="new-password" />
        <x-ui.button type="submit" variant="accent" block>Salvar nova senha</x-ui.button>
    </form>

    <div class="auth-links">
        <a href="{{ route('customer.password.request') }}">Pedir um novo link</a>
    </div>
</x-layouts.auth>

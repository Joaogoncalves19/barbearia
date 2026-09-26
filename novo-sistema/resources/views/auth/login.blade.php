<x-layouts.auth title="Entrar">
    <div class="stack stack-sm">
        <h1 class="h2">Acesso da equipe</h1>
        <p class="text-muted">Entre com o e-mail e a senha cadastrados pelo proprietário.</p>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="username" autofocus />
        <x-ui.input name="password" label="Senha" type="password" autocomplete="current-password" :value="null" />
        <x-ui.checkbox name="remember" label="Manter conectado neste aparelho" />
        <x-ui.button type="submit" block>Entrar</x-ui.button>
    </form>

    <p class="text-xs text-muted">Esqueceu a senha? Fale com o proprietário. A recuperação por e-mail chega na próxima fase.</p>
</x-layouts.auth>

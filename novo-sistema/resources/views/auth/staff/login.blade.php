<x-layouts.auth title="Entrar no painel">
    <div class="stack stack-sm">
        <h1 class="h2">Acesso da equipe</h1>
        <p class="text-muted">Entre com seu usuário ou e-mail e a senha.</p>
    </div>

    <form method="POST" action="{{ route('staff.login.attempt') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="identifier" label="Usuário ou e-mail" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus />
        <x-ui.input name="password" label="Senha" type="password" autocomplete="current-password" />
        <x-ui.checkbox name="remember" label="Manter conectado neste aparelho" hint="Só em aparelho pessoal. Vale por até 14 dias." />
        <x-ui.button type="submit" block>Entrar</x-ui.button>
    </form>

    <div class="auth-links">
        <a href="{{ route('staff.password.request') }}">Esqueci minha senha</a>
    </div>
</x-layouts.auth>

<x-layouts.auth title="Entrar" area="site">
    <div class="stack stack-sm">
        <h1 class="h2">Entrar na sua conta</h1>
        <p class="text-muted">Veja seus horários e seus dados.</p>
    </div>

    <form method="POST" action="{{ route('customer.login.attempt') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="username" autofocus />
        <x-ui.input name="password" label="Senha" type="password" autocomplete="current-password" />
        <x-ui.checkbox name="remember" label="Manter conectado neste aparelho" />
        <x-ui.button type="submit" variant="accent" block>Entrar</x-ui.button>
    </form>

    <x-ui.button :href="route('customer.magic.request')" variant="secondary" icon="mail" block>Receber link de acesso por e-mail</x-ui.button>

    <div class="auth-links">
        <a href="{{ route('customer.password.request') }}">Esqueci minha senha</a>
        <p class="text-muted">Ainda não tem conta? <a href="{{ route('customer.register') }}">Criar conta</a></p>
    </div>
</x-layouts.auth>

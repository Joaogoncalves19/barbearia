<x-layouts.auth title="Esqueci minha senha" area="site">
    <div class="stack stack-sm">
        <h1 class="h2">Esqueceu a senha?</h1>
        <p class="text-muted">Informe o e-mail da sua conta. Enviamos um link para criar uma nova senha.</p>
    </div>

    <form method="POST" action="{{ route('customer.password.email') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="email" autofocus />
        <x-ui.button type="submit" variant="accent" block>Enviar link</x-ui.button>
    </form>

    <div class="auth-links">
        <a href="{{ route('customer.magic.request') }}">Prefiro entrar sem senha</a>
        <a href="{{ route('customer.login') }}">Voltar para o login</a>
    </div>
</x-layouts.auth>

<x-layouts.auth title="Entrar com link" area="site">
    <div class="stack stack-sm">
        <h1 class="h2">Entrar sem senha</h1>
        <p class="text-muted">Informe seu e-mail. Enviamos um link de acesso que vale por {{ config('barbearia.security.magic_link_minutes') }} minutos e só pode ser usado uma vez.</p>
    </div>

    <form method="POST" action="{{ route('customer.magic.send') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="email" autofocus />
        <x-ui.button type="submit" variant="accent" block>Enviar link</x-ui.button>
    </form>

    <div class="auth-links">
        <a href="{{ route('customer.login') }}">Entrar com senha</a>
    </div>
</x-layouts.auth>

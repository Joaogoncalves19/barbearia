<x-layouts.auth title="Criar conta" area="site" wide>
    <div class="stack stack-sm">
        <h1 class="h2">Criar conta</h1>
        <p class="text-muted">Com a conta você acompanha seus horários. Depois do cadastro, confirme o e-mail para entrar.</p>
    </div>

    <form method="POST" action="{{ route('customer.register.store') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="name" label="Nome completo" autocomplete="name" autofocus />
        <x-ui.input name="email" label="E-mail" type="email" autocomplete="email" />
        <x-ui.input name="cpf" label="CPF" inputmode="numeric" autocomplete="off" hint="Só números ou com pontos e traço." />
        <x-ui.input name="phone" label="Celular com DDD" type="tel" autocomplete="tel-national" optional />
        <x-ui.input name="password" label="Senha" type="password" autocomplete="new-password" hint="Pelo menos 8 caracteres, com letras e números." />
        <x-ui.input name="password_confirmation" label="Repita a senha" type="password" autocomplete="new-password" />
        <x-ui.checkbox name="marketing" label="Quero receber novidades e promoções por e-mail" hint="Opcional. Você pode cancelar quando quiser." />
        <x-ui.button type="submit" variant="accent" block>Criar conta</x-ui.button>
    </form>

    <div class="auth-links">
        <p class="text-muted">Já tem conta? <a href="{{ route('customer.login') }}">Entrar</a></p>
    </div>
</x-layouts.auth>

<x-layouts.auth title="Esqueci minha senha">
    <div class="stack stack-sm">
        <h1 class="h2">Esqueceu a senha?</h1>
        <p class="text-muted">Informe seu usuário ou e-mail. Se sua conta tiver e-mail cadastrado, enviamos um link para criar uma nova senha.</p>
    </div>

    <form method="POST" action="{{ route('staff.password.email') }}" class="stack" novalidate>
        @csrf
        <x-ui.input name="identifier" label="Usuário ou e-mail" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus />
        <x-ui.button type="submit" block>Enviar link</x-ui.button>
    </form>

    <x-ui.alert title="Não tem e-mail cadastrado?">Peça ao proprietário uma senha provisória. Você cria a sua no primeiro acesso.</x-ui.alert>

    <div class="auth-links">
        <a href="{{ route('staff.login') }}">Voltar para o login</a>
    </div>
</x-layouts.auth>

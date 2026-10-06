<x-layouts.account title="Confirme sua senha">
    <header class="stack stack-sm">
        <p class="eyebrow">Segurança</p>
        <h1 class="h2">Confirme sua senha</h1>
        <p class="text-muted">Para proteger seus dados, exportar, excluir a conta ou trocar o e-mail pedem a senha de novo.</p>
    </header>

    @if ($hasPassword)
        <x-ui.card>
            <form method="POST" action="{{ route('account.confirm.store') }}" class="stack" novalidate>
                @csrf
                <x-ui.input name="password" label="Sua senha" type="password" autocomplete="current-password" />
                <div><x-ui.button type="submit" variant="accent">Confirmar</x-ui.button></div>
            </form>
        </x-ui.card>
    @else
        <x-ui.card title="Crie uma senha primeiro">
            <div class="stack">
                <p>Você entra pelo link enviado ao e-mail e ainda não tem senha. Crie uma para confirmar ações sensíveis da conta.</p>
                <div><x-ui.button :href="route('account.password.edit')" variant="accent" icon="key-round">Criar senha</x-ui.button></div>
            </div>
        </x-ui.card>
    @endif
</x-layouts.account>

<x-layouts.staff title="Confirmar senha">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Confirme sua senha</h1>
            <p class="text-muted">Esta área altera acessos da equipe. Por segurança, confirme sua senha para continuar (vale por 15 minutos).</p>
        </div>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('panel.password.confirm.store') }}" class="stack container-narrow" novalidate>
            @csrf
            <x-ui.input name="password" label="Sua senha" type="password" autocomplete="current-password" autofocus />
            <div><x-ui.button type="submit">Confirmar</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.staff>

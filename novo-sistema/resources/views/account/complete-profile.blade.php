<x-layouts.account title="Complete seu cadastro">
    <header class="stack stack-sm">
        <h1 class="h2">Falta só o seu CPF</h1>
        <p class="text-muted">O CPF é obrigatório para usar a conta. Ele aparece sempre mascarado na tela.</p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.complete.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <x-ui.input name="cpf" label="CPF" inputmode="numeric" autocomplete="off" hint="Só números ou com pontos e traço." autofocus />
            <div><x-ui.button type="submit" variant="accent">Continuar</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.account>

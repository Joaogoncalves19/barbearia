<x-layouts.account title="Trocar e-mail">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.profile.edit') }}">Voltar para meus dados</a>
        <h1 class="h2">Trocar e-mail</h1>
        <p class="text-muted">E-mail atual: {{ $customer->email }}. Enviamos um link para o endereço novo; o e-mail da conta só muda quando você confirmar por ele. O endereço atual recebe um aviso da troca.</p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.email.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <x-ui.input name="email" label="Novo e-mail" type="email" autocomplete="email" inputmode="email" />
            <div><x-ui.button type="submit" variant="accent" icon="mail">Enviar link de confirmação</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.account>

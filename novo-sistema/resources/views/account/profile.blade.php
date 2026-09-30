<x-layouts.account title="Meus dados">
    <header class="stack stack-sm">
        <h1 class="h2">Meus dados</h1>
        <p class="text-muted">Mantenha seu celular em dia para receber os avisos do seu horário.</p>
    </header>

    <x-ui.card>
        <form method="POST" action="{{ route('account.profile.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <x-ui.input name="name" label="Nome completo" autocomplete="name" :value="$customer->name" />
            <x-ui.input name="phone" label="Celular com DDD" type="tel" autocomplete="tel-national" :value="$customer->phone" optional />
            <x-ui.input name="birth_date" label="Data de nascimento" type="date" :value="$customer->birth_date?->format('Y-m-d')" optional />
            <div><x-ui.button type="submit" variant="accent">Salvar</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="E-mail e CPF">
        <dl class="summary-list">
            <div><dt>E-mail</dt><dd>{{ $customer->email }}</dd></div>
            <div><dt>CPF</dt><dd>{{ $customer->cpf ? \App\Modules\Customers\Support\Cpf::mask($customer->cpf) : 'Não informado' }}</dd></div>
        </dl>
        <p class="text-sm text-muted">Para corrigir o e-mail ou o CPF, fale com a barbearia.</p>
    </x-ui.card>
</x-layouts.account>

<x-layouts.staff title="Novo cliente">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.customers.index') }}">Voltar para clientes</a>
            <h1 class="page-head__title">Novo cliente</h1>
        </div>
    </header>

    <div class="dashboard-grid">
        <x-ui.card title="Dados do cliente">
            <form method="POST" action="{{ route('panel.customers.store') }}" class="stack" novalidate>
                @csrf
                <x-ui.input name="name" label="Nome" autocomplete="off" />
                <x-ui.input name="cpf" label="CPF" inputmode="numeric" autocomplete="off" hint="Obrigatório. Depois do cadastro aparece mascarado para a recepção." />
                <x-ui.input name="phone" label="Celular" type="tel" inputmode="tel" autocomplete="off" hint="Com DDD." optional />
                <x-ui.input name="birth_date" label="Data de nascimento" type="date" optional />
                <x-ui.input name="email" label="E-mail" type="email" autocomplete="off" autocapitalize="none" spellcheck="false" hint="O cliente recebe um link para confirmar o endereço e cria a própria senha pelo site." optional />

                <div><x-ui.button type="submit" icon="plus">Cadastrar cliente</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card title="Como fica a conta">
            <ul class="stack stack-sm text-sm">
                <li>Sem e-mail: o cliente é atendido só pelo balcão.</li>
                <li>Com e-mail: o cliente confirma o endereço pelo link e entra no site pelo link de acesso ou por "esqueci a senha". A senha é sempre criada por ele.</li>
                <li>Aceite de novidades por e-mail: só o próprio cliente dá, pela conta.</li>
            </ul>
        </x-ui.card>
    </div>
</x-layouts.staff>

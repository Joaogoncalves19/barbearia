<x-layouts.staff :title="'Editar · '.$customer->name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.customers.show', $customer->public_id) }}">Voltar para a ficha</a>
            <h1 class="page-head__title">{{ $customer->name }}</h1>
        </div>
    </header>

    <div class="dashboard-grid">
        <x-ui.card title="Dados do cliente">
            <form method="POST" action="{{ route('panel.customers.update', $customer->public_id) }}" class="stack" novalidate>
                @csrf
                @method('PUT')

                <x-ui.input name="name" label="Nome" autocomplete="off" :value="$customer->name" />
                <x-ui.input name="phone" label="Celular" type="tel" inputmode="tel" autocomplete="off" :value="$customer->phone" hint="Com DDD." optional />
                <x-ui.input name="birth_date" label="Data de nascimento" type="date" :value="$customer->birth_date?->format('Y-m-d')" optional />
                @if ($canEditCpf)
                    <x-ui.input name="cpf" label="CPF" inputmode="numeric" autocomplete="off" :value="$customer->cpf" :hint="$customer->cpf === null ? 'Não informado. Se ficar em branco, o cliente informa no próximo acesso.' : 'Só para corrigir: o CPF não pode ficar em branco.'" :optional="$customer->cpf === null" />
                @endif

                <div><x-ui.button type="submit">Salvar</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card title="O que a equipe não altera">
            <ul class="stack stack-sm text-sm">
                <li>E-mail e senha: são o acesso do cliente. A troca de e-mail é feita por ele, na conta, com confirmação no endereço novo.</li>
                <li>Novidades e lembretes por e-mail: a escolha e a prova do consentimento são do cliente.</li>
                @unless ($canEditCpf)<li>CPF: só proprietário e gerente corrigem.</li>@endunless
            </ul>
        </x-ui.card>
    </div>
</x-layouts.staff>

<x-layouts.staff :title="'Anonimizar · '.$customer->name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.customers.show', $customer->public_id) }}">Voltar para a ficha</a>
            <h1 class="page-head__title">Anonimizar {{ $customer->name }}</h1>
        </div>
    </header>

    @error('anonymize')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="O que acontece">
        <ul class="stack stack-sm text-sm">
            <li>Nome vira "Cliente removido"; e-mail, celular, CPF, nascimento e senha são apagados; o cliente não entra mais no site.</li>
            <li>Agendamentos, atendimentos, pagamentos e comissões continuam (obrigação fiscal e relatórios), sem o nome.</li>
            <li>Anotações, favoritos e avisos são apagados; avaliações ficam anônimas; a recusa de e-mails de novidades fica registrada.</li>
            <li>Fica na auditoria quem fez e quando, sem os dados pessoais. <strong>Não dá para desfazer.</strong></li>
        </ul>
    </x-ui.card>

    @if ($blockers !== [])
        <x-ui.alert variant="warning">
            <ul class="stack stack-sm" data-erasure-blockers>
                @foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @else
        <x-ui.card title="Confirmar">
            <form method="POST" action="{{ route('panel.customers.anonymize', $customer->public_id) }}" class="stack" novalidate>
                @csrf
                <x-ui.input name="confirmacao" label="Digite ANONIMIZAR para confirmar" autocomplete="off" autocapitalize="characters" spellcheck="false" />
                <div><x-ui.button type="submit" variant="danger">Anonimizar cadastro</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif
</x-layouts.staff>

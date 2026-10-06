<x-layouts.account title="Excluir minha conta">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.privacy') }}">Voltar para privacidade</a>
        <h1 class="h2">Excluir minha conta</h1>
    </header>

    @error('delete')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($blockers !== [])
        <x-ui.card title="Ainda não dá para excluir">
            <ul class="stack stack-sm" data-delete-blockers>
                @foreach ($blockers as $b)
                    <li>{{ $b }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    @else
        <x-ui.card title="O que acontece">
            <ul class="stack stack-sm text-sm">
                <li>Seu nome, e-mail, celular, CPF, data de nascimento e senha são apagados, e você sai da conta.</li>
                <li>O histórico de atendimentos e pagamentos continua na barbearia <strong>sem o seu nome</strong> (obrigação fiscal).</li>
                <li>Suas avaliações continuam, anônimas.</li>
                <li>Seus pontos de fidelidade e o código de indicação deixam de existir.</li>
                <li>Não dá para desfazer. Se quiser uma cópia dos seus dados, <a href="{{ route('account.privacy') }}">baixe antes</a>.</li>
            </ul>
        </x-ui.card>

        <x-ui.card>
            <form method="POST" action="{{ route('account.close.destroy') }}" class="stack" novalidate data-delete-form>
                @csrf
                @method('DELETE')
                <x-ui.input name="confirmation" label="Para confirmar, digite EXCLUIR" autocomplete="off" autocapitalize="characters" spellcheck="false" />
                <div><x-ui.button type="submit" variant="danger" icon="trash-2">Excluir minha conta para sempre</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif
</x-layouts.account>

{{-- Resumo dos erros da acao anterior (as acoes gravam pelas rotas do painel e voltam para ca). --}}
@if ($errors->any())
    <x-ui.alert variant="danger" role="alert">
        <ul class="pro-errors">
            @foreach ($errors->all() as $mensagem)
                <li>{{ $mensagem }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif

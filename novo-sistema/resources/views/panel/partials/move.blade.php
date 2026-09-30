{{--
    Subir/descer na ordem de exibicao (sem arrastar: funciona no teclado e
    no celular). Parametros: $route (nome da rota de ordem), $model, $label
    (nome do item, para o leitor de tela), $first, $last.
--}}
<div class="row-actions" role="group" aria-label="Ordem de {{ $label }}">
    <form method="POST" action="{{ route($route, $model) }}">
        @csrf
        <input type="hidden" name="direction" value="up">
        <button type="submit" class="btn btn--ghost btn--icon btn--sm" @disabled($first)>
            <x-icon name="arrow-up" label="Subir {{ $label }}" />
        </button>
    </form>
    <form method="POST" action="{{ route($route, $model) }}">
        @csrf
        <input type="hidden" name="direction" value="down">
        <button type="submit" class="btn btn--ghost btn--icon btn--sm" @disabled($last)>
            <x-icon name="arrow-down" label="Descer {{ $label }}" />
        </button>
    </form>
</div>

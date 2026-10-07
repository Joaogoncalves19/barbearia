{{--
    "Como funciona": explicacao de regra recolhida (details/summary nativo,
    teclado e leitor de tela sem JS). O texto continua na pagina; so nao
    ocupa a tela de trabalho o tempo todo.
    <x-ui.hint summary="Como o desconto funciona">texto...</x-ui.hint>
--}}
@props(['summary' => 'Como funciona'])
<details {{ $attributes->class(['hint']) }}>
    <summary><x-icon name="circle-help" class="icon-sm" /> {{ $summary }}</summary>
    <div class="hint__body">{{ $slot }}</div>
</details>

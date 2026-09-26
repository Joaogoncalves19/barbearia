{{--
    Menu de acoes. Slot "trigger" = conteudo do botao; slot padrao = itens
    (use a classe dropdown__item). Esc fecha e devolve o foco ao botao.
--}}
@props(['label' => 'Mais ações', 'align' => 'end'])
<div class="dropdown" x-data="dropdown" x-on:keydown.escape="close" x-on:click.outside="closeSilently" {{ $attributes }}>
    <button type="button" class="btn btn--ghost" x-ref="trigger" x-on:click="toggle" x-bind:aria-expanded="expanded" aria-haspopup="true">
        @isset($trigger){{ $trigger }}@else<x-icon name="ellipsis" /><span class="visually-hidden">{{ $label }}</span>@endisset
    </button>
    <div class="dropdown__menu {{ $align === 'start' ? 'dropdown__menu--start' : '' }}" x-ref="menu" x-show="open" x-cloak>
        {{ $slot }}
    </div>
</div>

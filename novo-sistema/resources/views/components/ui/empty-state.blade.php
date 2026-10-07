{{--
    Estado vazio: explica o que falta e oferece a proxima acao (slot "action").
    compact: uma linha (icone + frase), para listas dentro de um bloco ou de
    uma pagina que ja tem titulo; o texto de apoio (slot) e opcional.
--}}
@props(['title', 'icon' => 'calendar', 'compact' => false])
<div {{ $attributes->class(['state', 'state--compact' => $compact]) }}>
    <span class="state__icon"><x-icon :name="$icon" /></span>
    <p class="state__title">{{ $title }}</p>
    @if (trim((string) $slot) !== '')<p class="state__text">{{ $slot }}</p>@endif
    @isset($action)<div class="state__action">{{ $action }}</div>@endisset
</div>

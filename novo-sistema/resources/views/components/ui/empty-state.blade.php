{{-- Estado vazio: explica o que falta e oferece a proxima acao (slot "action"). --}}
@props(['title', 'icon' => 'calendar'])
<div {{ $attributes->class(['state']) }}>
    <span class="state__icon"><x-icon :name="$icon" /></span>
    <p class="state__title">{{ $title }}</p>
    <p class="state__text">{{ $slot }}</p>
    @isset($action)<div>{{ $action }}</div>@endisset
</div>

{{--
    Botao ou link com aparencia de botao.
    variant: primary | accent | secondary | ghost | danger
    size: sm | md | lg     href: vira <a>     loading: aria-busy + desabilitado
    icon / iconRight: nome do icone
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconRight' => null,
    'loading' => false,
    'block' => false,
])
@php
    $classes = [
        'btn',
        'btn--'.$variant => $variant !== 'primary',
        'btn--'.$size => $size !== 'md',
        'btn--block' => $block,
    ];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" />@endif
        <span>{{ $slot }}</span>
        @if ($iconRight)<x-icon :name="$iconRight" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @if ($loading) aria-busy="true" disabled @endif>
        @if ($icon)<x-icon :name="$icon" />@endif
        <span>{{ $slot }}</span>
        @if ($iconRight)<x-icon :name="$iconRight" />@endif
    </button>
@endif

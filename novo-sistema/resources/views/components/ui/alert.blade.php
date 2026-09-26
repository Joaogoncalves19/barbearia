{{--
    Mensagem em linha. variant: info | success | warning | danger.
    role="alert" so para erro (interrompe o leitor de tela); demais usam status.
--}}
@props(['variant' => 'info', 'title' => null])
@php
    $icones = ['info' => 'info', 'success' => 'circle-check', 'warning' => 'triangle-alert', 'danger' => 'circle-x'];
@endphp
<div {{ $attributes->class(['alert', 'alert--'.$variant => $variant !== 'info']) }} role="{{ $variant === 'danger' ? 'alert' : 'status' }}">
    <x-icon :name="$icones[$variant] ?? 'info'" />
    <div class="alert__body">
        @if ($title)<p class="alert__title">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>

{{-- Indicador de carregamento com texto (nunca so o spinner). --}}
@props(['label' => 'Carregando…'])
<div {{ $attributes->class(['loading']) }} role="status">
    <span class="spinner" aria-hidden="true"></span>
    <span>{{ $label }}</span>
</div>

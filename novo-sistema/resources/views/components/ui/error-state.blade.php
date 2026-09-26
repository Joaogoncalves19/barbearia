{{-- Estado de erro: diz o que aconteceu e como tentar de novo. Sem detalhes tecnicos. --}}
@props(['title' => 'Não foi possível carregar'])
<div {{ $attributes->class(['state', 'state--error']) }} role="alert">
    <span class="state__icon"><x-icon name="triangle-alert" /></span>
    <p class="state__title">{{ $title }}</p>
    <p class="state__text">{{ $slot }}</p>
    @isset($action)<div>{{ $action }}</div>@endisset
</div>

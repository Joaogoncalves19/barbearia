{{-- Superficie com borda. variant: default | flush | sunken. Titulo opcional com acoes. --}}
@props(['title' => null, 'variant' => null, 'as' => 'section'])
<{{ $as }} {{ $attributes->class(['card', 'card--'.$variant => $variant]) }}>
    @if ($title || isset($actions))
        <header class="card__header">
            @if ($title)<h2 class="title">{{ $title }}</h2>@endif
            @isset($actions)<div class="cluster">{{ $actions }}</div>@endisset
        </header>
    @endif
    {{ $slot }}
</{{ $as }}>

{{--
    Foto com proporcao fixa (evita salto de layout) e carregamento preguicoso.
    Sem src => placeholder IDENTIFICADO: diz qual foto real deve entrar ali.
    ratio: portrait (4:5) | square | landscape (3:2) | wide (16:9)
--}}
@props(['src' => null, 'alt' => '', 'ratio' => 'landscape', 'placeholder' => 'Foto a definir', 'eager' => false])
@if ($src)
    <figure {{ $attributes->class(['photo', 'ratio-'.$ratio]) }}>
        <img src="{{ $src }}" alt="{{ $alt }}" @if (! $eager) loading="lazy" @endif decoding="async">
    </figure>
@else
    <div {{ $attributes->class(['photo', 'photo--placeholder', 'ratio-'.$ratio]) }} role="img" aria-label="Espaço reservado para foto: {{ $placeholder }}">
        <span class="photo__label">
            <x-icon name="camera" />
            <span>{{ $placeholder }}</span>
            <small>Foto real pendente</small>
        </span>
    </div>
@endif

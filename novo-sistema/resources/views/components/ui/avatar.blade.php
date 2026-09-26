{{-- Foto redonda ou iniciais. size: sm | md | lg | xl --}}
@props(['name', 'src' => null, 'size' => 'md'])
@php
    $partes = preg_split('/\s+/', trim($name)) ?: [];
    $iniciais = mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1).mb_substr(count($partes) > 1 ? end($partes) : '', 0, 1));
@endphp
<span {{ $attributes->class(['avatar', 'avatar--'.$size => $size !== 'md']) }}>
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy" decoding="async">
    @else
        <span aria-hidden="true">{{ $iniciais }}</span>
    @endif
    <span class="visually-hidden">{{ $name }}</span>
</span>

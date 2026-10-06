{{--
    Imagem do site, sempre com largura/altura (sem salto de layout), srcset
    das variantes geradas no envio e carregamento preguicoso (exceto a
    principal do topo: eager + fetchpriority alta). Ver imagens.md.
    path: caminho no disco de midia; sizes: atributo sizes do <img>.
--}}
@props(['path', 'alt' => '', 'sizes' => '100vw', 'eager' => false, 'width' => null, 'height' => null])
@php
    $url = \App\Modules\Shared\Media\ImageStore::url($path);
    $srcset = \App\Modules\Shared\Media\ImageStore::srcset($path);
    $dim = \App\Modules\Shared\Media\ImageStore::dimensions($path);
    $w = $width ?? $dim[0] ?? null;
    $h = $height ?? $dim[1] ?? null;
@endphp
@if ($url)
    <img src="{{ $url }}" alt="{{ $alt }}" @if ($srcset !== '') srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        @if ($w && $h) width="{{ $w }}" height="{{ $h }}" @endif
        @if ($eager) fetchpriority="high" @else loading="lazy" @endif decoding="async" {{ $attributes }}>
@endif

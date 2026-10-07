{{--
    Documento HTML base de todas as paginas.
    direction: a (oficial). "b" so e aceito nas paginas de referencia, com a
               flag de prototipos ligada: carrega prototypes/direcao-b.css.
    surface:   parte da interface (site | panel | band | sidebar | auth), que o
               tema ativo traduz em superficie clara ou escura; aceita tambem
               'clara'/'escura' direto (comprovante, prototipos).
    O tema visual (temas-visuais.md) vai em data-tema no <html>: todos os
    tokens de cor, fonte e forma mudam juntos, em todas as areas.
--}}
@props([
    'title' => null,
    'description' => null,
    'direction' => 'a',
    'surface' => 'clara',
    'area' => 'site',
    'bodyClass' => null,
    'noindex' => false,
    'csrf' => true,
    // Fase 11: nome do site no titulo (site publico usa o nome da barbearia).
    'siteName' => null,
])
@php
    // Direcao B e so referencia historica: fora dos prototipos, sempre A.
    $direction = ($direction === 'b' && config('barbearia.prototypes_enabled')) ? 'b' : 'a';
    $theme = \App\Modules\SiteContent\Support\Theme::active();
    $surface = $theme->surface($surface);
@endphp
<!DOCTYPE html>
<html lang="pt-BR" data-direcao="{{ $direction }}" data-tema="{{ $theme->key }}" data-superficie="{{ $surface }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · ' : '' }}{{ $siteName ?? config('app.name') }}</title>
    @if ($description)<meta name="description" content="{{ $description }}">@endif
    @if ($noindex)<meta name="robots" content="noindex, nofollow">@endif
    @if ($csrf)<meta name="csrf-token" content="{{ csrf_token() }}">@endif
    <meta name="theme-color" content="{{ $theme->browserColor($surface) }}">
    @vite(['resources/css/app.css', 'resources/css/'.($area === 'panel' ? 'panel' : 'site').'.css', 'resources/js/app.js'])
    @if ($direction === 'b')@vite('resources/css/prototypes/direcao-b.css')@endif
    @stack('head')
</head>
<body @class([$area, $bodyClass])>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    {{ $slot }}
</body>
</html>

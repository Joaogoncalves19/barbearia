{{--
    Documento HTML base de todas as paginas.
    direction: a (oficial). "b" so e aceito nas paginas de referencia, com a
               flag de prototipos ligada: carrega prototypes/direcao-b.css.
    surface:   escura (site) | clara (painel)
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
@endphp
<!DOCTYPE html>
<html lang="pt-BR" data-direcao="{{ $direction }}" data-superficie="{{ $surface }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · ' : '' }}{{ $siteName ?? config('app.name') }}</title>
    @if ($description)<meta name="description" content="{{ $description }}">@endif
    @if ($noindex)<meta name="robots" content="noindex, nofollow">@endif
    @if ($csrf)<meta name="csrf-token" content="{{ csrf_token() }}">@endif
    <meta name="theme-color" content="{{ $surface === 'escura' ? '#121110' : '#f8f5ef' }}">
    @vite(['resources/css/app.css', 'resources/css/'.($area === 'panel' ? 'panel' : 'site').'.css', 'resources/js/app.js'])
    @if ($direction === 'b')@vite('resources/css/prototypes/direcao-b.css')@endif
    @stack('head')
</head>
<body @class([$area, $bodyClass])>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    {{ $slot }}
</body>
</html>

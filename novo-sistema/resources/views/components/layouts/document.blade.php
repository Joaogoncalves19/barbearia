{{--
    Documento HTML base de todas as paginas.
    direction: a | b (direcao visual, vai no <html>: ver tokens.css)
    surface:   escura (site) | clara (painel)
--}}
@props([
    'title' => null,
    'description' => null,
    'direction' => config('barbearia.design.default_direction', 'a'),
    'surface' => 'clara',
    'area' => 'site',
    'bodyClass' => null,
    'noindex' => false,
    'csrf' => true,
])
<!DOCTYPE html>
<html lang="pt-BR" data-direcao="{{ in_array($direction, ['a', 'b'], true) ? $direction : 'a' }}" data-superficie="{{ $surface }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @if ($description)<meta name="description" content="{{ $description }}">@endif
    @if ($noindex)<meta name="robots" content="noindex, nofollow">@endif
    @if ($csrf)<meta name="csrf-token" content="{{ csrf_token() }}">@endif
    <meta name="theme-color" content="{{ $surface === 'escura' ? '#121110' : '#f8f5ef' }}">
    @vite(['resources/css/app.css', 'resources/css/'.($area === 'panel' ? 'panel' : 'site').'.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body @class([$area, $bodyClass])>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    {{ $slot }}
</body>
</html>

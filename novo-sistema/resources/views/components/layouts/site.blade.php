{{--
    Layout do SITE PUBLICO (superficie escura, experiencia de marca).
    :nav = [['label' => 'Serviços', 'href' => ..., 'current' => bool], ...]
--}}
@props([
    'title' => null,
    'description' => null,
    'direction' => 'a',
    'brand' => 'Barbearia',
    'nav' => [],
    'bookingUrl' => '#',
    'whatsappUrl' => null,
    'homeUrl' => '/',
    'prototype' => false,
    'bottomBar' => true,
    // Fase 11 (site real): logo enviado no painel e nome no titulo.
    'logo' => null,
    'siteName' => null,
    'noindex' => false,
])
<x-layouts.document :title="$title" :description="$description" :direction="$direction" surface="escura" area="site" :noindex="$prototype || $noindex" :site-name="$siteName">
    @if ($prototype)
        @include('partials.prototype-banner', ['direcao' => $direction])
    @endif

    <header class="site-header" x-data="disclosure" x-on:keydown.escape="close">
        <div class="container site-header__inner">
            <a class="brand" href="{{ $homeUrl }}">
                @if ($logo)
                    <img class="brand__logo" src="{{ $logo['url'] }}" alt="{{ $brand }}" @if ($logo['width']) width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" @endif>
                @else
                    <span class="brand__mark" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
                    <span class="brand__name">{{ $brand }}</span>
                @endif
            </a>

            <nav class="site-nav" aria-label="Principal">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" @if (! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <div class="cluster">
                <x-ui.button :href="$bookingUrl" variant="accent" class="site-header__cta">Agendar horário</x-ui.button>
                <button type="button" class="btn btn--ghost btn--icon site-menu-toggle" x-on:click="toggle" x-bind:aria-expanded="expanded" aria-controls="menu-movel">
                    <x-icon name="menu" label="Abrir menu" />
                </button>
            </div>
        </div>

        <nav id="menu-movel" class="mobile-menu" aria-label="Menu" x-show="open" x-cloak>
            <div class="container">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" x-on:click="close" @if (! empty($item['current'])) aria-current="page" @endif>
                        {{ $item['label'] }} <x-icon name="arrow-right" />
                    </a>
                @endforeach
            </div>
        </nav>
    </header>

    <main id="conteudo">
        {{-- Recado de uma acao que terminou fora da conta (ex.: conta excluida, Fase 12). --}}
        @if (session('status'))
            <div class="container site-flash">
                <x-ui.alert variant="success" role="status">{{ session('status') }}</x-ui.alert>
            </div>
        @endif
        {{ $slot }}
    </main>

    {{ $footer ?? '' }}

    @if ($bottomBar)
        <div class="bottom-bar">
            <x-ui.button :href="$bookingUrl" variant="accent" block icon="calendar">Agendar horário</x-ui.button>
            @if ($whatsappUrl)
                <x-ui.button :href="$whatsappUrl" variant="secondary" class="btn--icon" rel="noopener" target="_blank">
                    <x-icon name="message-circle" label="Falar no WhatsApp" />
                </x-ui.button>
            @endif
        </div>
    @endif
</x-layouts.document>

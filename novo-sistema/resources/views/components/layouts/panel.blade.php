{{--
    Layout do PAINEL (redesign "Oficio"): barra lateral GRAFITE com o menu
    por tarefa, conteudo claro. O topo mostra so o que serve: o dia de hoje e
    o menu da pessoa (minha conta, senha, sair). Nada de controle de enfeite:
    a busca e o sino de prototipo sairam (nao faziam nada).
    :nav = [['group' => 'Hoje', 'items' => [['label','href','icon','current']]]]
--}}
@props([
    'title' => null,
    'direction' => 'a',
    'brand' => 'Barbearia',
    'nav' => [],
    'userName' => 'Usuário',
    'userRole' => null,
    'logoutUrl' => null,
    'accountUrl' => null,
    'passwordUrl' => null,
    'prototype' => false,
])
@php
    $hoje = \Carbon\CarbonImmutable::now(config('barbearia.display_timezone', 'America/Sao_Paulo'))->locale('pt_BR');
@endphp
<x-layouts.document :title="$title" :direction="$direction" surface="panel" area="panel" :noindex="true">
    @if ($prototype)
        @include('partials.prototype-banner', ['direcao' => $direction])
    @endif

    <div class="panel-shell" x-data="disclosure" x-on:keydown.escape="close">
        <aside class="sidebar" data-superficie="{{ \App\Modules\SiteContent\Support\Theme::active()->surface('sidebar') }}" x-bind:class="panelClass" id="menu-painel" aria-label="Menu do painel">
            <div class="sidebar__brand">
                <span class="brand">
                    <span class="brand__mark" aria-hidden="true">{{ mb_substr($brand, 0, 1) }}</span>
                    <span class="brand__name">{{ $brand }}</span>
                </span>
                <button type="button" class="btn btn--ghost btn--icon btn--sm sidebar__close" x-on:click="close">
                    <x-icon name="x" label="Fechar menu" />
                </button>
            </div>

            <nav class="sidebar__nav">
                @foreach ($nav as $grupo)
                    <div class="nav-group">
                        @if (! empty($grupo['group']))<p class="nav-group__title">{{ $grupo['group'] }}</p>@endif
                        @foreach ($grupo['items'] as $item)
                            <a class="nav-link" href="{{ $item['href'] }}" @if (! empty($item['current'])) aria-current="page" @endif>
                                <x-icon :name="$item['icon']" /> {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="sidebar__footer">{{ $sidebarFooter ?? '' }}</div>
        </aside>
        <div class="sidebar-backdrop" x-show="open" x-on:click="close" x-cloak></div>

        <div class="panel-body">
            <header class="topbar">
                <button type="button" class="btn btn--ghost btn--icon topbar__menu" x-on:click="toggle" x-bind:aria-expanded="expanded" aria-controls="menu-painel">
                    <x-icon name="menu" label="Abrir menu" />
                </button>
                <p class="topbar__date"><span class="topbar__weekday">{{ \Illuminate\Support\Str::ucfirst($hoje->translatedFormat('l')) }}</span>, {{ $hoje->translatedFormat('d \\d\\e F') }}</p>
                <div class="topbar__actions">
                    <x-ui.dropdown label="Menu do usuário">
                        <x-slot:trigger>
                            <x-ui.avatar :name="$userName" size="sm" />
                            <span class="user-button__name">{{ $userName }}</span>
                            <x-icon name="chevron-down" class="icon-sm" />
                        </x-slot:trigger>
                        @if ($userRole)<p class="dropdown__item text-muted">{{ $userRole }}</p><div class="dropdown__separator"></div>@endif
                        @if ($accountUrl)<a class="dropdown__item" href="{{ $accountUrl }}"><x-icon name="settings" /> Minha conta</a>@endif
                        @if ($passwordUrl)<a class="dropdown__item" href="{{ $passwordUrl }}"><x-icon name="key-round" /> Senha</a>@endif
                        @if ($logoutUrl)
                            <form method="POST" action="{{ $logoutUrl }}">
                                @csrf
                                <button type="submit" class="dropdown__item"><x-icon name="log-out" /> Sair</button>
                            </form>
                        @endif
                    </x-ui.dropdown>
                </div>
            </header>

            <main id="conteudo" class="panel-main">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.document>

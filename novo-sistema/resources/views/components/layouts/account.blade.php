{{--
    Area do CLIENTE (fundacao da Fase 3; a area completa e da Fase 12).
    Mesma marca do site (superficie escura), conteudo em coluna estreita.
--}}
@props(['title' => null])
@php
    $nav = [
        ['label' => 'Minha conta', 'route' => 'account.home', 'pattern' => 'account.home'],
        ['label' => 'Meus dados', 'route' => 'account.profile.edit', 'pattern' => 'account.profile.*'],
        ['label' => 'Fidelidade', 'route' => 'account.loyalty', 'pattern' => 'account.loyalty*'],
        ['label' => 'Senha', 'route' => 'account.password.edit', 'pattern' => 'account.password.*'],
    ];
    $completo = ! auth('customer')->user()?->needsProfileCompletion();
@endphp
<x-layouts.document :title="$title" surface="escura" area="site" :noindex="true">
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            @if ($completo)
                <nav class="site-nav" aria-label="Minha conta">
                    @foreach ($nav as $item)
                        <a href="{{ route($item['route']) }}" @if (request()->routeIs($item['pattern'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            @endif

            <form method="POST" action="{{ route('account.logout') }}">
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm" icon="log-out">Sair</x-ui.button>
            </form>
        </div>
    </header>

    <main id="conteudo" class="section section--tight">
        <div class="container-narrow stack stack-lg">
            @if ($completo)
                {{-- No celular o menu do cabecalho some: os mesmos links ficam aqui. --}}
                <nav class="segmented account-nav" aria-label="Minha conta (atalhos)">
                    @foreach ($nav as $item)
                        <a class="segmented__item" href="{{ route($item['route']) }}" @if (request()->routeIs($item['pattern'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            @endif

            @if (session('status'))
                <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
            @endif

            {{ $slot }}
        </div>
    </main>
</x-layouts.document>

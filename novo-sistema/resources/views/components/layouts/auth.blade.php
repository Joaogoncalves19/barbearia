{{--
    Telas de acesso (login, cadastro, senha) — redesign "Oficio".
    Desktop: a marca num painel de grafite (nome, para que serve a area, a
    regua) e o formulario ao lado. Celular: a marca vira uma faixa curta em
    cima. Sem menu, sem distracao.
    area="panel": equipe (formulario em superficie clara).
    area="site":  clientes (tudo escuro, como o site).
--}}
@props(['title' => null, 'area' => 'panel', 'wide' => false])
@php
    $marca = \App\Modules\SiteContent\Support\Brand::current();
    $equipe = $area !== 'site';
@endphp
<x-layouts.document :title="$title" :surface="$area === 'site' ? 'escura' : 'clara'" :area="$area" :noindex="true">
    <main id="conteudo" @class(['auth-page', 'auth-page--staff' => $equipe, 'auth-page--site' => ! $equipe])>
        <aside class="auth-brand" data-superficie="escura">
            <a class="brand" href="{{ $equipe ? route('staff.login') : route('home') }}">
                @if ($marca['logo'])
                    <img class="brand__logo" src="{{ $marca['logo']['url'] }}" alt="{{ $marca['name'] }}" @if ($marca['logo']['width']) width="{{ $marca['logo']['width'] }}" height="{{ $marca['logo']['height'] }}" @endif>
                @else
                    <span class="brand__mark" aria-hidden="true">{{ mb_substr($marca['name'], 0, 1) }}</span>
                    <span class="brand__name">{{ $marca['name'] }}</span>
                @endif
            </a>
            <div class="auth-brand__claim">
                <p class="eyebrow">{{ $equipe ? 'Painel da equipe' : 'Sua conta' }}</p>
                <p class="auth-brand__title caps">{{ $equipe ? 'Agenda, comanda e caixa do dia.' : 'Horários, comprovantes e benefícios.' }}</p>
            </div>
            <span class="ruler ruler--accent auth-brand__ruler" aria-hidden="true"></span>
        </aside>

        <div class="auth-panel">
            <div @class(['auth-card', 'auth-card--wide' => $wide, 'stack', 'stack-lg'])>
                @if (session('status'))
                    <x-ui.alert variant="success" role="status">{{ session('status') }}</x-ui.alert>
                @endif

                {{ $slot }}
            </div>
        </div>
    </main>
</x-layouts.document>

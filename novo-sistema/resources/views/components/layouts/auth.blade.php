{{--
    Telas de acesso (login, cadastro, senha).
    area="panel": equipe (superficie clara, produtividade).
    area="site":  clientes (superficie escura, experiencia de marca).
    Sem menu, sem distracao: a marca e o formulario.
--}}
@props(['title' => null, 'area' => 'panel', 'wide' => false])
<x-layouts.document :title="$title" :surface="$area === 'site' ? 'escura' : 'clara'" :area="$area" :noindex="true">
    <main id="conteudo" class="auth-page">
        <div @class(['auth-card', 'auth-card--wide' => $wide, 'stack', 'stack-lg'])>
            <a class="brand" href="{{ $area === 'site' ? route('home') : route('staff.login') }}">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            @if (session('status'))
                <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
            @endif

            {{ $slot }}
        </div>
    </main>
</x-layouts.document>

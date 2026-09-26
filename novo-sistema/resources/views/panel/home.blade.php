{{-- Painel real (Fase 1): so confirma autenticacao, autorizacao e layout. --}}
@php
    $user = auth()->user();
    $nav = [['group' => '', 'items' => [
        ['label' => 'Início', 'icon' => 'house', 'href' => route('panel.home'), 'current' => true],
    ]]];
@endphp
<x-layouts.panel title="Painel" :brand="config('app.name')" :nav="$nav" :user-name="$user->name" :user-role="$user->role->label()" :logout-url="route('logout')">
    <header class="page-head">
        <div class="stack stack-sm">
            <p class="text-muted">{{ $user->role->label() }}</p>
            <h1 class="page-head__title">Olá, {{ strtok($user->name, ' ') }}</h1>
        </div>
    </header>

    <x-ui.empty-state title="A fundação está pronta" icon="circle-check">
        Os módulos (agenda, clientes, caixa…) serão construídos a partir da Fase 2, sobre esta base.
    </x-ui.empty-state>

    @can('system.health.view')
        <x-ui.alert title="Saúde do sistema">Rode <code>php artisan app:diagnose</code> no servidor para verificar banco, fila, agendador e e-mail.</x-ui.alert>
    @endcan
</x-layouts.panel>

{{-- Inicio do painel. Os modulos (agenda, clientes, caixa...) chegam a partir da Fase 4. --}}
@php $user = auth('web')->user(); @endphp
<x-layouts.staff title="Painel">
    <header class="page-head">
        <div class="stack stack-sm">
            <p class="text-muted">{{ $user->role?->label() }}</p>
            <h1 class="page-head__title">Olá, {{ strtok($user->name, ' ') }}</h1>
        </div>
    </header>

    <x-ui.empty-state title="Acesso e permissões prontos" icon="shield-check">
        Os módulos (agenda, clientes, caixa…) serão construídos a partir da Fase 4, sobre esta base.
    </x-ui.empty-state>

    @can('system.health.view')
        <x-ui.alert title="Saúde do sistema">Rode <code>php artisan app:diagnose</code> no servidor para verificar banco, fila, agendador e e-mail.</x-ui.alert>
    @endcan
</x-layouts.staff>

{{--
    Painel REAL da equipe: o layout do painel com o menu montado a partir das
    permissoes de quem esta logado. O menu so esconde o que a pessoa nao
    pode usar; quem protege de verdade e a rota (auth + can:) e a Policy.
--}}
@props(['title' => null])
@php
    /** @var \App\Modules\Identity\Models\User $user */
    $user = auth('web')->user();
    $item = fn (string $label, string $icon, string $route, string $pattern) => [
        'label' => $label, 'icon' => $icon, 'href' => route($route), 'current' => request()->routeIs($pattern),
    ];

    $operacao = [$item('Início', 'house', 'panel.home', 'panel.home')];
    if ($user->can('agenda.view')) {
        $operacao[] = $item('Agenda', 'calendar-days', 'panel.agenda', 'panel.agenda');
    }
    if ($user->professional !== null) {
        $minhaFicha = route('panel.professionals.show', $user->professional);
        $operacao[] = ['label' => 'Minha ficha', 'icon' => 'user', 'href' => $minhaFicha, 'current' => request()->url() === $minhaFicha];
    }

    $cadastros = [];
    if ($user->can('services.view')) {
        $cadastros[] = $item('Serviços', 'scissors', 'panel.services.index', 'panel.services.*');
        $cadastros[] = $item('Categorias', 'tag', 'panel.categories.index', 'panel.categories.*');
    }
    if ($user->can('professionals.view')) {
        $cadastros[] = $item('Profissionais', 'users', 'panel.professionals.index', 'panel.professionals.*');
    }

    $configAgenda = [];
    if ($user->can('schedule.settings')) {
        $configAgenda[] = $item('Funcionamento', 'clock', 'panel.schedule.settings', 'panel.schedule.settings*');
    }
    if ($user->can('schedule.time_off')) {
        $configAgenda[] = $item('Folgas', 'coffee', 'panel.time-off.index', 'panel.time-off.*');
    }
    if ($user->can('schedule.blocks')) {
        $configAgenda[] = $item('Bloqueios', 'circle-x', 'panel.blocks.index', 'panel.blocks.*');
    }

    $admin = [];
    if ($user->can('users.manage')) {
        $admin[] = $item('Usuários', 'users', 'panel.users.index', 'panel.users.*');
    }
    if ($user->can('audit.view')) {
        $admin[] = $item('Auditoria', 'history', 'panel.audit.index', 'panel.audit.*');
    }

    $nav = [['group' => '', 'items' => $operacao]];
    if ($configAgenda !== []) {
        $nav[] = ['group' => 'Configurar agenda', 'items' => $configAgenda];
    }
    if ($cadastros !== []) {
        $nav[] = ['group' => 'Cadastros', 'items' => $cadastros];
    }
    if ($admin !== []) {
        $nav[] = ['group' => 'Administração', 'items' => $admin];
    }
    $nav[] = ['group' => 'Conta', 'items' => [
        $item('Minha conta', 'settings', 'panel.account.edit', 'panel.account.*'),
        $item('Senha', 'key-round', 'panel.password.edit', 'panel.password.*'),
    ]];
@endphp
<x-layouts.panel :title="$title" :brand="config('app.name')" :nav="$nav" :user-name="$user->name" :user-role="$user->role?->label()" :logout-url="route('staff.logout')" :account-url="route('panel.account.edit')">
    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    {{ $slot }}
</x-layouts.panel>

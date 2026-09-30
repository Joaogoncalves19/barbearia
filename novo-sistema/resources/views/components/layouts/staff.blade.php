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
    if ($user->professional !== null) {
        $operacao[] = [
            'label' => 'Minha ficha', 'icon' => 'user',
            'href' => route('panel.professionals.show', $user->professional),
            'current' => request()->routeIs('panel.professionals.*'),
        ];
    }

    $admin = [];
    if ($user->can('users.manage')) {
        $admin[] = $item('Usuários', 'users', 'panel.users.index', 'panel.users.*');
    }
    if ($user->can('audit.view')) {
        $admin[] = $item('Auditoria', 'history', 'panel.audit.index', 'panel.audit.*');
    }

    $nav = [['group' => '', 'items' => $operacao]];
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

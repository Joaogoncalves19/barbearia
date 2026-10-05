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
    if ($user->can('viewAny', \App\Modules\Checkout\Models\Attendance::class)) {
        $operacao[] = $item('Atendimentos', 'receipt', 'panel.attendances.index', 'panel.attendances.*');
    }
    if ($user->can('cash.view')) {
        $operacao[] = $item('Caixa', 'wallet', 'panel.cash.index', 'panel.cash.*');
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
    if ($user->can('products.view')) {
        $cadastros[] = $item('Produtos e estoque', 'package', 'panel.products.index', 'panel.products.*');
    }

    // Financeiro (Fase 7): comissao, gorjeta, vales e repasse.
    $financeiro = [];
    if ($user->can('commissions.view')) {
        $financeiro[] = $item('Comissões', 'hand-coins', 'panel.commissions.index', 'panel.commissions.index');
    } elseif ($user->can('commissions.view_own') && $user->professional !== null) {
        $financeiro[] = $item('Minhas comissões', 'hand-coins', 'panel.commissions.mine', 'panel.commissions.show');
    }
    if ($user->can('payouts.view')) {
        $financeiro[] = $item('Repasses', 'banknote', 'panel.payouts.index', 'panel.payouts.*');
    }
    if ($user->can('commissions.configure')) {
        $financeiro[] = $item('Regras de comissão', 'percent', 'panel.commission-rules.index', 'panel.commission-rules.*');
    }
    if ($user->can('commissions.history')) {
        $financeiro[] = $item('Histórico financeiro', 'history', 'panel.commissions.history', 'panel.commissions.history');
    }

    // Promocoes, fidelidade e vale-presente (Fase 8).
    $promocoes = [];
    if ($user->can('coupons.view')) {
        $promocoes[] = $item('Cupons', 'tag', 'panel.coupons.index', 'panel.coupons.*');
    }
    if ($user->can('gift_cards.view')) {
        $promocoes[] = $item('Vales-presente', 'receipt', 'panel.gift-cards.index', 'panel.gift-cards.*');
    }
    if ($user->can('loyalty.view')) {
        $promocoes[] = $item('Pontos de clientes', 'star', 'panel.loyalty.customers', 'panel.loyalty.*');
    }
    if ($user->can('promotions.configure')) {
        $promocoes[] = $item('Fidelidade e aniversário', 'sparkles', 'panel.promotions.settings', 'panel.promotions.*');
    }

    // Assinaturas (Fase 9).
    $assinaturas = [];
    if ($user->can('subscriptions.view')) {
        $assinaturas[] = $item('Assinaturas', 'badge-check', 'panel.subscriptions.index', 'panel.subscriptions.*');
    }
    if ($user->can('plans.manage')) {
        $assinaturas[] = $item('Planos', 'list', 'panel.plans.index', 'panel.plans.*');
    }

    // Comunicacao e avaliacoes (Fase 10).
    $comunicacao = [];
    if ($user->can('viewAny', \App\Modules\Reviews\Models\Review::class)) {
        $comunicacao[] = $item('Avaliações', 'star', 'panel.reviews.index', 'panel.reviews.*');
    }
    if ($user->can('campaigns.view')) {
        $comunicacao[] = $item('Campanhas', 'mail', 'panel.campaigns.index', 'panel.campaigns.*');
    }
    if ($user->can('communications.view')) {
        $comunicacao[] = $item('E-mails enviados', 'history', 'panel.emails.index', 'panel.emails.*');
    }
    if ($user->can('communications.settings')) {
        $comunicacao[] = $item('Lembretes e avisos', 'bell', 'panel.communication.settings', 'panel.communication.*');
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
    if ($financeiro !== []) {
        $nav[] = ['group' => 'Financeiro', 'items' => $financeiro];
    }
    if ($promocoes !== []) {
        $nav[] = ['group' => 'Promoções', 'items' => $promocoes];
    }
    if ($assinaturas !== []) {
        $nav[] = ['group' => 'Assinaturas', 'items' => $assinaturas];
    }
    if ($comunicacao !== []) {
        $nav[] = ['group' => 'Comunicação', 'items' => $comunicacao];
    }
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

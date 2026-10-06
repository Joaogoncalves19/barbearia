{{--
    Area do CLIENTE (Fase 12). Mesma marca do site (superficie escura).
    Desktop: menu lateral + conteudo em coluna estreita. Celular: o menu vira
    uma faixa de atalhos que rola SO dentro dela (a pagina nunca rola de lado).
--}}
@props(['title' => null])
@php
    $cliente = auth('customer')->user();
    $naoLidos = $cliente ? \App\Modules\Customers\Models\CustomerNotification::query()->where('customer_id', $cliente->id)->whereNull('read_at')->count() : 0;
    $nav = [
        ['label' => 'Início', 'icon' => 'house', 'route' => 'account.home', 'pattern' => 'account.home'],
        ['label' => 'Agendamentos', 'icon' => 'calendar', 'route' => 'account.appointments.index', 'pattern' => ['account.appointments.*', 'account.booking.*']],
        ['label' => 'Comprovantes', 'icon' => 'receipt', 'route' => 'account.receipts.index', 'pattern' => ['account.receipts.*', 'account.attendances.*']],
        ['label' => 'Benefícios', 'icon' => 'sparkles', 'route' => 'account.loyalty', 'pattern' => 'account.loyalty*'],
        ['label' => 'Assinatura', 'icon' => 'badge-check', 'route' => 'account.subscription', 'pattern' => 'account.subscription*'],
        ['label' => 'Avaliações', 'icon' => 'star', 'route' => 'account.reviews.index', 'pattern' => 'account.reviews.*'],
        ['label' => 'Avisos', 'icon' => 'bell', 'route' => 'account.notifications', 'pattern' => 'account.notifications*', 'count' => $naoLidos],
        ['label' => 'Meus dados', 'icon' => 'user', 'route' => 'account.profile.edit', 'pattern' => ['account.profile.*', 'account.password.*', 'account.email.*']],
        ['label' => 'Privacidade', 'icon' => 'shield-check', 'route' => 'account.privacy', 'pattern' => ['account.privacy*', 'account.close*', 'account.confirm.*']],
    ];
    $completo = $cliente !== null && ! $cliente->needsProfileCompletion();
@endphp
<x-layouts.document :title="$title" surface="escura" area="site" :noindex="true">
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            <div class="cluster">
                @if ($completo)
                    <x-ui.button :href="route('booking.services')" variant="accent" size="sm" icon="calendar-plus" class="account-header__cta">Agendar</x-ui.button>
                @endif
                <form method="POST" action="{{ route('account.logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm" icon="log-out">Sair</x-ui.button>
                </form>
            </div>
        </div>
    </header>

    <main id="conteudo" class="section section--tight">
        <div class="container account-shell @if (! $completo) account-shell--single @endif">
            @if ($completo)
                <nav class="account-menu" aria-label="Minha conta">
                    <ul role="list">
                        @foreach ($nav as $item)
                            @php $atual = request()->routeIs(...(array) $item['pattern']); @endphp
                            <li>
                                <a href="{{ route($item['route']) }}" @if ($atual) aria-current="page" @endif>
                                    <x-icon :name="$item['icon']" />
                                    <span>{{ $item['label'] }}</span>
                                    @if (($item['count'] ?? 0) > 0)
                                        <span class="account-menu__count">{{ $item['count'] }}<span class="visually-hidden"> não lidos</span></span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <div class="account-content stack stack-lg">
                @if (session('status'))
                    <x-ui.alert variant="success" role="status">{{ session('status') }}</x-ui.alert>
                @endif

                {{ $slot }}
            </div>
        </div>
    </main>
</x-layouts.document>

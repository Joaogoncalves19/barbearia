{{--
    Moldura da AREA DO PROFISSIONAL (Fase 12.5, painel-profissional.md).
    Ferramenta de trabalho, pensada primeiro para o celular: barra do topo com
    a barbearia e a pessoa, cinco destinos (Hoje, Agenda, Atendimentos,
    Ganhos, Perfil) numa barra fixa embaixo no celular e num trilho a
    esquerda no desktop. Uma navegacao so no HTML (um landmark), que o CSS
    posiciona. Cores, fontes e formas: tokens do tema da instalacao.

    Tambem e a moldura das telas do painel que o profissional ja usava
    (novo agendamento, remarcar, encaixe, senha, recibo): o layout do painel
    troca o menu administrativo por esta quando a pessoa tem a area.
--}}
@props(['title' => null])
@php
    use App\Modules\SiteContent\Support\Theme;
    /** @var \App\Modules\Identity\Models\User $user */
    $user = auth('web')->user();
    $pro = $user->professional;
    $marca = \App\Modules\SiteContent\Support\Brand::current()['name'];
    $hoje = \Carbon\CarbonImmutable::now(config('barbearia.display_timezone', 'America/Sao_Paulo'))->locale('pt_BR');
    $destinos = [
        ['Hoje', 'house', 'pro.today', ['pro.today', 'panel.home']],
        ['Agenda', 'calendar-days', 'pro.agenda', ['pro.agenda', 'pro.appointments.*', 'panel.agenda', 'panel.appointments.*']],
        ['Atendimentos', 'receipt', 'pro.attendances', ['pro.attendances*', 'panel.attendances.*', 'panel.receipts.attendance*']],
        ['Ganhos', 'hand-coins', 'pro.earnings', ['pro.earnings', 'panel.payouts.*', 'panel.receipts.payout*', 'panel.commissions.*']],
        ['Perfil', 'user', 'pro.profile', ['pro.profile', 'panel.account.*', 'panel.password.*', 'panel.reviews.*', 'panel.professionals.*']],
    ];
    $sup = Theme::active()->surface('sidebar');
@endphp
<x-layouts.document :title="$title" surface="panel" area="panel" :noindex="true" body-class="pro">
    <div class="pro-shell">
        <header class="pro-bar" data-superficie="{{ $sup }}">
            <a class="pro-bar__brand" href="{{ route('pro.today') }}">
                <span class="brand__mark" aria-hidden="true">{{ mb_substr($marca, 0, 1) }}</span>
                <span class="pro-bar__names">
                    <span class="pro-bar__shop">{{ $marca }}</span>
                    <span class="pro-bar__who">{{ $pro?->display_name ?? $user->name }}</span>
                </span>
            </a>
            <p class="pro-bar__date"><span class="pro-bar__weekday">{{ \Illuminate\Support\Str::ucfirst($hoje->translatedFormat('l')) }}</span>, {{ $hoje->translatedFormat('d \\d\\e F') }}</p>
            <x-ui.dropdown label="Menu da conta" class="pro-bar__menu">
                <x-slot:trigger>
                    <x-ui.avatar :name="$user->name" size="sm" />
                    <span class="visually-hidden">Menu da conta</span>
                    <x-icon name="chevron-down" class="icon-sm" />
                </x-slot:trigger>
                <p class="dropdown__item text-muted">{{ $user->name }}</p>
                <div class="dropdown__separator"></div>
                <a class="dropdown__item" href="{{ route('pro.profile') }}"><x-icon name="user" /> Meu perfil</a>
                <a class="dropdown__item" href="{{ route('panel.password.edit') }}"><x-icon name="key-round" /> Senha</a>
                <form method="POST" action="{{ route('staff.logout') }}">
                    @csrf
                    <button type="submit" class="dropdown__item"><x-icon name="log-out" /> Sair</button>
                </form>
            </x-ui.dropdown>
        </header>

        <nav class="pro-nav" aria-label="Área do profissional" data-superficie="{{ $sup }}">
            <ul class="pro-nav__list" role="list">
                @foreach ($destinos as [$rotulo, $icone, $rota, $padroes])
                    <li>
                        <a class="pro-nav__link" href="{{ route($rota) }}" @if (request()->routeIs(...$padroes)) aria-current="page" @endif>
                            <x-icon :name="$icone" />
                            <span>{{ $rotulo }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <main id="conteudo" class="pro-main">
            @if (session('status'))
                <x-ui.alert variant="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.document>

{{--
    Inicio do painel (redesign): o dia da barbearia. Quem esta na cadeira
    agora, o que vem a seguir, o caixa e o que pede atencao. Cada bloco so
    aparece para quem pode ver (DashboardController).
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $podeAgendar = $user->can('appointments.manage') || $user->can('appointments.manage_own');
    $podeEncaixe = $user->can('create', \App\Modules\Checkout\Models\Attendance::class);
@endphp
<x-layouts.staff title="Hoje">
    <header class="page-head">
        <div class="stack stack-sm">
            <p class="eyebrow">Hoje na barbearia</p>
            <h1 class="page-head__title">{{ $greeting }}, {{ strtok($user->name, ' ') }}</h1>
        </div>
        @if ($podeAgendar || $podeEncaixe)
            <div class="cluster">
                @if ($podeEncaixe)
                    <x-ui.button :href="route('panel.attendances.create')" variant="secondary" icon="armchair">Encaixe</x-ui.button>
                @endif
                @if ($podeAgendar)
                    <x-ui.button :href="route('panel.appointments.create')" icon="calendar-plus">Novo agendamento</x-ui.button>
                @endif
            </div>
        @endif
    </header>

    @if ($canSeeAgenda)
        {{-- O dia em quatro numeros: uma faixa, nao quatro cards. --}}
        <dl class="stats day-stats" aria-label="O dia em números">
            <div class="stat"><dt class="stat__label">Horários hoje</dt><dd class="stat__value" data-count="total">{{ $counts['total'] }}</dd></div>
            <div class="stat"><dt class="stat__label">Concluídos</dt><dd class="stat__value">{{ $counts['done'] }}</dd></div>
            <div class="stat"><dt class="stat__label">A seguir</dt><dd class="stat__value">{{ $counts['next'] }}</dd></div>
            <div class="stat"><dt class="stat__label">Faltas</dt><dd class="stat__value">{{ $counts['noShow'] }}</dd></div>
        </dl>
    @endif

    <div class="dashboard-grid">
        <div class="stack stack-lg">
            @if ($inProgress->isNotEmpty())
                <section class="board" aria-labelledby="agora">
                    <header class="board__head">
                        <h2 id="agora" class="title"><span class="live-dot" aria-hidden="true"></span> Na cadeira agora</h2>
                    </header>
                    <ul class="now-list" role="list">
                        @foreach ($inProgress as $at)
                            <li>
                                <a class="now-item" href="{{ route('panel.attendances.show', $at) }}">
                                    <span class="now-item__since">
                                        <span class="text-xs text-muted">desde</span>
                                        <span class="figure figure--sm">{{ BusinessTime::formatLocal($at->started_at ?? $at->opened_at, 'H:i') }}</span>
                                    </span>
                                    <span class="now-item__who">
                                        <strong>{{ $at->customer_name }}</strong>
                                        <span class="text-muted">{{ $at->items->pluck('name')->join(', ') ?: 'Atendimento' }} · {{ $at->professional_name }}</span>
                                    </span>
                                    <span class="now-item__go">Abrir comanda <x-icon name="arrow-right" /></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($canSeeAgenda)
                <section class="board" aria-labelledby="proximos">
                    <header class="board__head">
                        <h2 id="proximos" class="title">Próximos horários</h2>
                        <a class="link-arrow text-sm" href="{{ route('panel.agenda') }}">Agenda do dia <x-icon name="arrow-right" /></a>
                    </header>
                    @if ($upcoming->isEmpty())
                        <x-ui.empty-state title="Nada mais marcado para hoje" icon="calendar">
                            Os próximos horários aparecem aqui assim que alguém agenda.
                        </x-ui.empty-state>
                    @else
                        <ol class="next-list" role="list">
                            @foreach ($upcoming as $a)
                                <li>
                                    <a class="next-item" href="{{ route('panel.appointments.show', $a) }}">
                                        <span class="next-item__time figure figure--sm">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
                                        <span class="next-item__who">
                                            <strong>{{ $a->customer_name }}</strong>
                                            <span class="text-muted">{{ $a->items->pluck('name')->join(', ') }}</span>
                                        </span>
                                        <span class="next-item__pro">{{ $a->professional_name }}</span>
                                        {{-- Sinais do dia (so leitura): ja devia ter comecado e o cliente
                                             nao chegou; encaixe; ainda nao confirmado. --}}
                                        <span class="next-item__flags">
                                            @if ($a->starts_at !== null && $a->starts_at->lt($now))
                                                <x-ui.badge variant="danger">Atrasado</x-ui.badge>
                                            @endif
                                            @if ($a->source === \App\Modules\Scheduling\Enums\AppointmentSource::WalkIn)
                                                <x-ui.badge variant="info">Encaixe</x-ui.badge>
                                            @endif
                                            @if ($a->status->value !== 'confirmed')
                                                <x-ui.badge variant="warning">{{ $a->status->label() }}</x-ui.badge>
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            @endif
        </div>

        <aside class="stack stack-lg" aria-label="Caixa e pendências">
            @if ($cash !== null)
                <section @class(['cash-tile', 'is-open' => $cash['session'] !== null]) aria-labelledby="caixa-titulo">
                    <h2 id="caixa-titulo" class="stat__label">Caixa</h2>
                    @if ($cash['session'] !== null)
                        <p class="cash-tile__state"><span class="live-dot" aria-hidden="true"></span> Aberto desde {{ BusinessTime::formatLocal($cash['session']->opened_at, 'H:i') }}</p>
                        <p class="cash-tile__value">
                            <span class="figure figure--lg numeric">{{ Money::fromCents((int) $cash['expected'])->format() }}</span>
                            <span class="text-sm">esperado na gaveta (dinheiro)</span>
                        </p>
                        <x-ui.button :href="route('panel.cash.index')" variant="secondary" size="sm" icon="wallet">Ver caixa</x-ui.button>
                    @else
                        <p class="cash-tile__state">Fechado</p>
                        <p class="text-sm text-muted">Abra o caixa antes de receber o primeiro pagamento do dia.</p>
                        <x-ui.button :href="route('panel.cash.index')" size="sm" icon="wallet">Abrir caixa</x-ui.button>
                    @endif
                </section>
            @endif

            <section class="board" aria-labelledby="pendencias">
                <header class="board__head"><h2 id="pendencias" class="title">Pede atenção</h2></header>
                @if ($pending === [])
                    <p class="text-sm text-muted">Nada pendente. Tudo em dia.</p>
                @else
                    <ul class="todo-list" role="list">
                        @foreach ($pending as $p)
                            <li><a href="{{ $p['href'] }}"><x-icon :name="$p['icon']" /> <span>{{ $p['text'] }}</span> <x-icon name="chevron-right" class="icon-sm" /></a></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @can('system.health.view')
                <p class="text-xs text-muted">Saúde do sistema: <code>php artisan app:diagnose</code> no servidor verifica banco, fila, agendador e e-mail.</p>
            @endcan
        </aside>
    </div>
</x-layouts.staff>

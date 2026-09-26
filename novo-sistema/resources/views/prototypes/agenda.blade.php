{{-- TELA DE REFERENCIA 5 — Agenda do dia (colunas por profissional). Dados de EXEMPLO. --}}
@php
    $classeStatus = ['done' => 'agenda__event--done', 'pending' => 'agenda__event--pending', 'break' => 'agenda__event--break', 'confirmed' => ''];
    $rotuloStatus = ['done' => 'Concluído', 'pending' => 'Aguardando confirmação', 'confirmed' => 'Confirmado', 'break' => 'Intervalo'];
@endphp
<x-layouts.panel title="Agenda (referência)" :direction="$direcao" :brand="$brand" :nav="$panelNav"
    user-name="Ana Recepção" user-role="Recepção" prototype>

    @push('head')
        {{-- Posicao dos eventos na grade: regras geradas no servidor, com nonce (CSP sem style=""). --}}
        <style nonce="{{ Vite::cspNonce() }}">
            .agenda__grid { --cols: {{ count($pros) }}; --rows: {{ $rows }}; }
            @foreach ($events as $e)
                .{{ $e['class'] }} { grid-row: {{ $e['row'] }} / span {{ $e['span'] }}; grid-column: {{ $e['col'] }}; }
            @endforeach
            @foreach ($times as $i => $t)
                .t-{{ $i }} { grid-row: {{ $i + 2 }}; }
            @endforeach
            @foreach ($pros as $c => $p)
                .c-{{ $c }} { grid-column: {{ $c + 2 }}; }
            @endforeach
        </style>
    @endpush

    <header class="page-head">
        <div class="stack stack-sm">
            <x-ui.breadcrumbs :items="[['label' => 'Painel', 'href' => route('prototypes.dashboard', $q)], ['label' => 'Agenda']]" />
            <h1 class="page-head__title">Agenda</h1>
        </div>
        <x-ui.button icon="plus" data-dialog-open="novo-agendamento">Novo agendamento</x-ui.button>
    </header>

    <div class="agenda-toolbar">
        <div class="agenda-date">
            <x-ui.button variant="ghost" class="btn--icon"><x-icon name="chevron-left" label="Dia anterior" /></x-ui.button>
            <span class="agenda-date__label">Quinta, 12 de março</span>
            <x-ui.button variant="ghost" class="btn--icon"><x-icon name="chevron-right" label="Próximo dia" /></x-ui.button>
            <x-ui.button variant="secondary" size="sm">Hoje</x-ui.button>
        </div>
        <div class="cluster">
            <nav class="segmented" aria-label="Visualização">
                <a class="segmented__item" href="{{ route('prototypes.agenda', $q) }}" @if ($view === 'dia') aria-current="page" @endif>Dia</a>
                <a class="segmented__item" href="{{ route('prototypes.agenda', $q + ['visao' => 'lista']) }}" @if ($view === 'lista') aria-current="page" @endif>Lista</a>
            </nav>
            <x-ui.badge variant="sample">Dados de exemplo</x-ui.badge>
        </div>
    </div>

    <div class="legend" aria-label="Legenda">
        <x-ui.badge variant="info">Confirmado</x-ui.badge>
        <x-ui.badge variant="warning">Aguardando confirmação</x-ui.badge>
        <x-ui.badge variant="success">Concluído</x-ui.badge>
        <x-ui.badge>Intervalo</x-ui.badge>
    </div>

    @if ($view === 'dia')
        <div class="agenda" role="region" aria-label="Grade da agenda do dia" tabindex="0">
            <div class="agenda__grid">
                <div class="agenda__corner"><span class="visually-hidden">Horário</span></div>
                @foreach ($pros as $c => $p)
                    <div class="agenda__pro c-{{ $c }}">
                        <x-ui.avatar :name="$p['name']" size="sm" />
                        <span>{{ strtok($p['name'], ' ') }}<small>{{ collect($events)->where('pro', $p['name'])->where('status', '!=', 'break')->count() }} atendimentos</small></span>
                    </div>
                @endforeach

                @foreach ($times as $i => $t)
                    <div class="agenda__time t-{{ $i }}">{{ $t['hour'] ? $t['label'] : '' }}</div>
                    @foreach ($pros as $c => $p)
                        <div class="agenda__cell t-{{ $i }} c-{{ $c }} {{ $t['hour'] ? 'is-hour' : '' }}"></div>
                    @endforeach
                @endforeach

                @foreach ($events as $e)
                    @if ($e['status'] === 'break')
                        <div class="agenda__event agenda__event--break {{ $e['class'] }}"><strong>{{ $e['service'] }}</strong><span>{{ $e['time'] }}–{{ $e['end'] }}</span></div>
                    @else
                        <button type="button" class="agenda__event {{ $classeStatus[$e['status']] }} {{ $e['class'] }}" data-dialog-open="detalhe-agendamento"
                            aria-label="{{ $e['time'] }} a {{ $e['end'] }}, {{ $e['client'] }}, {{ $e['service'] }}, com {{ $e['pro'] }}, {{ $rotuloStatus[$e['status']] }}">
                            <strong>{{ $e['client'] }}</strong>
                            <span>{{ $e['time'] }}–{{ $e['end'] }} · {{ $e['service'] }}</span>
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
    @else
        <div class="agenda-list">
            @foreach ($pros as $p)
                @php $doPro = collect($events)->where('pro', $p['name'])->where('status', '!=', 'break')->sortBy('start'); @endphp
                <x-ui.table :caption="$p['name'].' — '.$doPro->count().' atendimentos'" stacked>
                    <thead><tr><th scope="col">Horário</th><th scope="col">Cliente</th><th scope="col">Serviço</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($doPro as $e)
                            <tr>
                                <td data-label="Horário" class="numeric">{{ $e['time'] }}–{{ $e['end'] }}</td>
                                <td data-label="Cliente">{{ $e['client'] }}</td>
                                <td data-label="Serviço">{{ $e['service'] }}</td>
                                <td data-label="Situação"><x-ui.badge :variant="['done' => 'success', 'pending' => 'warning', 'confirmed' => 'info'][$e['status']]">{{ $rotuloStatus[$e['status']] }}</x-ui.badge></td>
                                <td data-label="Ações">
                                    <x-ui.dropdown :label="'Ações para '.$e['client']">
                                        <button type="button" class="dropdown__item"><x-icon name="check" /> Marcar como concluído</button>
                                        <button type="button" class="dropdown__item"><x-icon name="calendar" /> Remarcar</button>
                                        <div class="dropdown__separator"></div>
                                        <button type="button" class="dropdown__item dropdown__item--danger" data-dialog-open="confirmar-cancelamento"><x-icon name="circle-x" /> Cancelar</button>
                                    </x-ui.dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endforeach
        </div>
    @endif

    <x-ui.modal id="detalhe-agendamento" title="Detalhes do agendamento">
        <p>Estrutura do painel de detalhes (Fase 5): cliente, serviços com preço congelado, histórico e ações.</p>
        <x-slot:footer>
            <button type="button" class="btn btn--secondary" data-dialog-close>Fechar</button>
            <button type="button" class="btn" data-dialog-close>Abrir comanda</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="novo-agendamento" title="Novo agendamento">
        <p>Formulário real na Fase 5.</p>
        <x-slot:footer><button type="button" class="btn" data-dialog-close>Fechar</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.confirm id="confirmar-cancelamento" title="Cancelar agendamento?" confirm-label="Cancelar agendamento">
        O horário volta a ficar livre e o cliente é avisado. Esta ação fica registrada no histórico.
    </x-ui.confirm>
</x-layouts.panel>

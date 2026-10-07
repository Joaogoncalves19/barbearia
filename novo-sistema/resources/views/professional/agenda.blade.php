{{--
    AGENDA do profissional (Fase 12.5): um dia em linha do tempo, so a
    propria agenda. Semana com a contagem de cada dia, navegacao por data,
    tempo livre (Availability::dayOverview), pausas, bloqueios e folga.
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $agora = BusinessTime::now();
@endphp
<x-layouts.professional title="Minha agenda">
    <header class="pro-head">
        <div class="pro-head__text">
            <p class="eyebrow">Minha agenda</p>
            <h1 class="pro-head__title">{{ \Illuminate\Support\Str::ucfirst($dayLabel) }}</h1>
        </div>
        <div class="pro-head__actions">
            @if ($canWalkIn)
                <x-ui.button :href="route('panel.attendances.create')" variant="secondary" icon="armchair">Encaixe</x-ui.button>
            @endif
            @if ($canBook)
                <x-ui.button :href="route('panel.appointments.create', ['data' => $date])" variant="secondary" icon="calendar-plus">Agendar</x-ui.button>
            @endif
            <button type="button" class="btn btn--ghost pro-print" data-print><x-icon name="printer" /><span>Imprimir</span></button>
        </div>
    </header>

    @include('professional.partials.errors')

    <nav class="week" aria-label="Semana">
        <a class="week__step" href="{{ route('pro.agenda', ['data' => $prevWeek]) }}"><x-icon name="chevron-left" label="Semana anterior" /></a>
        <ol class="week__days" role="list">
            @foreach ($week as $d)
                <li>
                    <a class="week__day @if ($d['date'] === $today) is-today @endif" href="{{ route('pro.agenda', ['data' => $d['date']]) }}" @if ($d['date'] === $date) aria-current="date" @endif>
                        <span class="week__weekday">{{ rtrim($d['weekday'], '.') }}</span>
                        <span class="week__num figure">{{ $d['day'] }}</span>
                        <span class="week__count">{{ $d['count'] > 0 ? $d['count'] : '·' }}<span class="visually-hidden"> {{ $d['count'] === 1 ? 'horário' : 'horários' }}</span></span>
                    </a>
                </li>
            @endforeach
        </ol>
        <a class="week__step" href="{{ route('pro.agenda', ['data' => $nextWeek]) }}"><x-icon name="chevron-right" label="Próxima semana" /></a>
    </nav>

    {{-- No celular a semana ja escolhe o dia; os botoes de dia ficam para telas maiores. --}}
    <div class="day-bar">
        <div class="cluster day-bar__steps">
            <x-ui.button :href="route('pro.agenda', ['data' => $prev])" variant="secondary" size="sm" icon="chevron-left">Dia anterior</x-ui.button>
            <x-ui.button :href="route('pro.agenda', ['data' => $next])" variant="secondary" size="sm" icon-right="chevron-right">Próximo dia</x-ui.button>
        </div>
        @if ($date !== $today)
            <x-ui.button :href="route('pro.agenda')" variant="ghost" size="sm" icon="calendar">Voltar para hoje</x-ui.button>
        @endif
        <details class="hint day-jump">
            <summary><x-icon name="calendar-days" /> Outro dia</summary>
            <form method="GET" action="{{ route('pro.agenda') }}" class="day-bar__jump hint__body">
                <x-ui.input name="data" type="date" label="Ir para o dia" :value="$date" />
                <x-ui.button type="submit" variant="secondary" size="sm">Ir</x-ui.button>
            </form>
        </details>
    </div>

    <p class="day-summary">
        <strong>{{ $counts['total'] }}</strong> {{ $counts['total'] === 1 ? 'horário' : 'horários' }}
        · {{ $counts['done'] }} {{ $counts['done'] === 1 ? 'concluído' : 'concluídos' }}
        @if ($counts['noShow'] > 0) · {{ $counts['noShow'] }} {{ $counts['noShow'] === 1 ? 'falta' : 'faltas' }}@endif
        @if ($counts['cancelled'] > 0) · {{ $counts['cancelled'] }} {{ $counts['cancelled'] === 1 ? 'cancelado' : 'cancelados' }}@endif
    </p>

    @if ($timeOff)
        <x-ui.alert variant="info">Folga neste dia ({{ $timeOff->kind->label() }}). A agenda não recebe horários.</x-ui.alert>
    @elseif (! $works && $rows->isEmpty())
        <x-ui.empty-state title="Sem expediente neste dia" icon="calendar">Seu expediente e as pausas são definidos pela gerência.</x-ui.empty-state>
    @endif

    @if ($rows->isNotEmpty())
        <ol class="timeline-pro timeline-pro--day" role="list" aria-label="Linha do tempo do dia">
            @foreach ($rows as $linha)
                @switch($linha['kind'])
                    @case('appointment')
                        @include('professional.partials.appointment-row', ['a' => $linha['item'], 'now' => $agora])
                        @break
                    @case('block')
                        @include('professional.partials.gap-row', ['kind' => 'block', 'start' => $linha['item']->starts_at, 'end' => $linha['item']->ends_at, 'reason' => $linha['item']->professional_id === null ? 'barbearia' : null])
                        @break
                    @default
                        @include('professional.partials.gap-row', ['kind' => $linha['kind'], 'start' => $linha['item']->start, 'end' => $linha['item']->end,
                            'bookUrl' => $linha['kind'] === 'free' && $canBook ? route('panel.appointments.create', ['data' => $date, 'hora' => BusinessTime::formatLocal($linha['item']->start, 'H:i')]) : null])
                @endswitch
            @endforeach
        </ol>
    @elseif (! $timeOff && $works)
        <x-ui.empty-state compact title="Nenhum horário neste dia" icon="calendar" />
    @endif

    <x-ui.hint summary="Como o tempo livre é calculado">
        <p>É o seu expediente no funcionamento da barbearia, menos pausas, bloqueios e horários marcados: a mesma regra que decide se um horário pode ser agendado. Para agendar, o serviço também precisa caber inteiro no tempo livre.</p>
    </x-ui.hint>
</x-layouts.professional>

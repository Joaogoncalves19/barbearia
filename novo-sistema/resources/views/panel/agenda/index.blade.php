{{--
    Agenda do dia (redesign): linha do tempo com uma coluna por profissional.
    Cada horario ocupa a altura da sua duracao (grade de 5 minutos); a regua
    a esquerda marca as horas; a linha de latao e o "agora". Situacao sempre
    em texto (nao so cor). Posicoes geradas no servidor num <style> com nonce
    (a CSP nao aceita style=""). No celular o quadro rola de lado SO dentro
    dele, com a regua presa a esquerda.
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use Illuminate\Support\Facades\Vite;

    $passo = 5; // minutos por linha da grade
    $linhas = intdiv($rangeEnd - $rangeStart, $passo);
    $min = fn ($t) => (int) BusinessTime::local($t)->format('H') * 60 + (int) BusinessTime::local($t)->format('i');
    $linha = fn (int $m) => max(1, min($linhas, intdiv(max(0, $m - $rangeStart), $passo) + 1));
    $estilos = [];
    $colunas = [];
    foreach ($professionals as $c => $p) {
        $itens = [];
        foreach (collect($appointments->get($p->id, collect())) as $a) {
            $ini = BusinessTime::dateOf($a->starts_at) === $date ? $min($a->starts_at) : $rangeStart;
            $fim = BusinessTime::dateOf($a->ends_at) === $date ? $min($a->ends_at) : $rangeEnd;
            $emAtendimento = $a->attendance !== null && in_array($a->attendance->status->value, ['open', 'in_progress'], true);
            $estado = $emAtendimento ? 'in-progress' : $a->status->value;
            $itens[] = ['type' => 'appt', 'model' => $a, 'start' => $ini, 'end' => max($fim, $ini + $passo), 'state' => $estado, 'inProgress' => $emAtendimento];
        }
        foreach ($blocks->filter(fn ($b) => $b->professional_id === null || $b->professional_id === $p->id) as $b) {
            $ini = BusinessTime::dateOf($b->starts_at) === $date ? $min($b->starts_at) : $rangeStart;
            $fim = BusinessTime::dateOf($b->ends_at) === $date ? $min($b->ends_at) : $rangeEnd;
            $itens[] = ['type' => 'block', 'model' => $b, 'start' => $ini, 'end' => max($fim, $ini + $passo), 'state' => 'block'];
        }
        usort($itens, fn ($x, $y) => $x['start'] <=> $y['start']);
        // Conflito: dois itens que se sobrepoem na mesma coluna (raro: a agenda impede; encaixe/legado).
        foreach ($itens as $i => &$it) {
            $it['conflict'] = false;
            foreach ($itens as $j => $outro) {
                if ($i !== $j && $it['type'] === 'appt' && $outro['type'] === 'appt' && $outro['state'] !== 'cancelled' && $it['state'] !== 'cancelled'
                    && $it['start'] < $outro['end'] && $outro['start'] < $it['end']) {
                    $it['conflict'] = true;
                }
            }
            $it['class'] = 'ev-'.$c.'-'.$i;
            $estilos[] = '.'.$it['class'].'{grid-row:'.$linha($it['start']).' / span '.max(1, intdiv($it['end'] - $it['start'], $passo)).'}';
        }
        unset($it);
        $colunas[] = ['pro' => $p, 'items' => $itens, 'off' => in_array($p->id, $offToday, true)];
    }
    $nomeMin = fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    $rotulos = ['pending' => 'A confirmar', 'awaiting_payment' => 'Aguardando pagamento', 'confirmed' => 'Confirmado', 'in-progress' => 'Em atendimento',
        'completed' => 'Concluído', 'no_show' => 'Não compareceu', 'cancelled' => 'Cancelado'];
@endphp
<x-layouts.staff title="Agenda">
    @push('head')
        <style nonce="{{ Vite::cspNonce() }}">
            .day-board { --rows: {{ max(1, $linhas) }}; --cols: {{ max(1, count($colunas)) }}; }
            @foreach (range(0, intdiv($rangeEnd - $rangeStart, 60)) as $h)
                .hr-{{ $h }} { grid-row: {{ $h * 12 + 1 }}; }
            @endforeach
            @if ($nowMinutes !== null)
                .day-board__now { --now-row: {{ intdiv($nowMinutes - $rangeStart, $passo) }}; }
            @endif
            {!! implode("\n", $estilos) !!}
        </style>
    @endpush

    <header class="page-head agenda-head">
        <div class="stack stack-sm">
            <p class="eyebrow">Agenda{{ $date === $today ? ' · hoje' : '' }}</p>
            <h1 class="page-head__title">{{ ucfirst($dayLabel) }}</h1>
        </div>
        @if (auth('web')->user()->can('appointments.manage') || auth('web')->user()->can('appointments.manage_own'))
            <x-ui.button :href="route('panel.appointments.create', ['data' => $date])" icon="calendar-plus">Novo agendamento</x-ui.button>
        @endif
    </header>

    <form method="GET" action="{{ route('panel.agenda') }}" class="agenda-bar">
        <div class="agenda-bar__nav" role="group" aria-label="Trocar de dia">
            <x-ui.button :href="route('panel.agenda', array_filter(['data' => $prev, 'profissional' => $filter, 'cancelados' => $showCancelled ? 1 : null]))" variant="secondary" class="btn--icon"><x-icon name="chevron-left" label="Dia anterior" /></x-ui.button>
            @if ($date !== $today)
                <x-ui.button :href="route('panel.agenda', array_filter(['profissional' => $filter, 'cancelados' => $showCancelled ? 1 : null]))" variant="secondary">Hoje</x-ui.button>
            @endif
            <x-ui.button :href="route('panel.agenda', array_filter(['data' => $next, 'profissional' => $filter, 'cancelados' => $showCancelled ? 1 : null]))" variant="secondary" class="btn--icon"><x-icon name="chevron-right" label="Próximo dia" /></x-ui.button>
        </div>
        <x-ui.input name="data" label="Dia" type="date" :value="$date" class="control--date" />
        @if ($allProfessionals->isNotEmpty())
            <x-ui.select name="profissional" label="Profissional" :options="['' => 'Todos'] + $allProfessionals->pluck('display_name', 'id')->all()" :value="$filter" optional />
        @endif
        <x-ui.checkbox name="cancelados" label="Mostrar cancelados" :checked="$showCancelled" />
        <x-ui.button type="submit" variant="secondary">Ver</x-ui.button>
    </form>

    @if ($professionals->isEmpty())
        <x-ui.empty-state title="Nenhuma agenda para mostrar" icon="calendar">
            Não há profissional ativo ligado à sua conta. Peça para a gerência vincular sua ficha de profissional.
        </x-ui.empty-state>
    @else
        <ul class="agenda-legend" aria-label="Legenda">
            <li><span class="swatch swatch--confirmed"></span> Confirmado</li>
            <li><span class="swatch swatch--pending"></span> A confirmar</li>
            <li><span class="swatch swatch--in-progress"></span> Em atendimento</li>
            <li><span class="swatch swatch--completed"></span> Concluído</li>
            <li><span class="swatch swatch--block"></span> Bloqueio</li>
        </ul>

        <div class="day-board-wrap" tabindex="0" role="region" aria-label="Linha do tempo do dia (role de lado para ver todos os profissionais)">
            <div class="day-board">
                <div class="day-board__head">
                    <span class="day-board__corner" aria-hidden="true"></span>
                    @foreach ($colunas as $col)
                        @php $qtd = collect($col['items'])->where('type', 'appt')->whereNotIn('state', ['cancelled'])->count(); @endphp
                        <div class="day-board__pro">
                            <x-ui.avatar :name="$col['pro']->display_name" size="sm" :src="$col['pro']->photoUrl()" />
                            <span>
                                <strong>{{ $col['pro']->display_name }}</strong>
                                <span class="text-xs text-muted">{{ $col['off'] ? 'De folga' : ($qtd === 1 ? '1 horário' : $qtd.' horários') }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="day-board__body">
                    <ol class="day-board__axis" aria-hidden="true">
                        @foreach (range(0, intdiv($rangeEnd - $rangeStart, 60)) as $h)
                            <li class="hr-{{ $h }}">{{ $nomeMin($rangeStart + $h * 60) }}</li>
                        @endforeach
                    </ol>

                    @foreach ($colunas as $col)
                        <ol @class(['day-col', 'is-off' => $col['off']]) aria-label="Agenda de {{ $col['pro']->display_name }}">
                            @if ($col['off'])
                                <li class="day-col__off">De folga</li>
                            @endif
                            @if ($col['items'] === [] && ! $col['off'])
                                <li class="day-col__empty">Livre o dia todo</li>
                            @endif
                            @foreach ($col['items'] as $it)
                                @if ($it['type'] === 'appt')
                                    @php $a = $it['model']; @endphp
                                    <li @class(['ev', $it['class'], 'ev--'.$it['state'], 'is-conflict' => $it['conflict'], 'is-short' => ($it['end'] - $it['start']) <= 30])>
                                        <a href="{{ route('panel.appointments.show', $a) }}">
                                            <span class="ev__top">
                                                <span class="ev__time numeric">{{ $nomeMin($it['start']) }}–{{ $nomeMin($it['end']) }}</span>
                                                <span class="ev__state">
                                                    @if ($it['conflict'])
                                                        <x-icon name="triangle-alert" class="icon-sm" /> Conflito ·
                                                    @endif
                                                    {{ $rotulos[$it['state']] ?? $a->status->label() }}
                                                </span>
                                            </span>
                                            <strong class="ev__who">{{ $a->customer_name }}</strong>
                                            <span class="ev__what">{{ $a->items->pluck('name')->join(', ') }}</span>
                                        </a>
                                    </li>
                                @else
                                    @php $b = $it['model']; @endphp
                                    <li class="ev ev--block {{ $it['class'] }}">
                                        <span class="ev__time numeric">{{ $nomeMin($it['start']) }}–{{ $nomeMin($it['end']) }}</span>
                                        <strong class="ev__who">Bloqueado{{ $b->professional_id === null ? ' · barbearia' : '' }}</strong>
                                        <span class="ev__what">{{ $b->reason }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    @endforeach

                    @if ($nowMinutes !== null)
                        <div class="day-board__now" aria-hidden="true"><span>{{ $nomeMin($nowMinutes) }}</span></div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-layouts.staff>

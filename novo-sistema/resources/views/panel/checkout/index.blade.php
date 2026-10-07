@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $hoje = BusinessTime::today();
    $anterior = \Carbon\CarbonImmutable::parse($date)->subDay()->toDateString();
    $proximo = \Carbon\CarbonImmutable::parse($date)->addDay()->toDateString();
    $cor = fn ($s) => match ($s->value) { 'open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', default => 'neutral' };
@endphp
<x-layouts.staff title="Atendimentos">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Atendimentos</h1>
            <p class="text-muted">{{ \Carbon\CarbonImmutable::parse($date)->locale('pt_BR')->translatedFormat('l, d \d\e F') }}@if ($date === $hoje) · hoje @endif</p>
        </div>
        @if ($canOpen)
            <x-ui.button :href="route('panel.attendances.create')" icon="plus">Encaixe</x-ui.button>
        @endif
    </header>

    <p class="text-sm text-muted">Cliente agendado chegou? Abra o atendimento pela <a href="{{ route('panel.agenda', ['data' => $date]) }}">agenda</a>, no agendamento dele.</p>

    <form method="GET" action="{{ route('panel.attendances.index') }}" class="agenda-bar">
        <div class="agenda-bar__nav" role="group" aria-label="Trocar de dia">
            <x-ui.button :href="route('panel.attendances.index', ['data' => $anterior])" variant="secondary" class="btn--icon"><x-icon name="chevron-left" label="Dia anterior" /></x-ui.button>
            @if ($date !== $hoje)
                <x-ui.button :href="route('panel.attendances.index')" variant="secondary">Hoje</x-ui.button>
            @endif
            <x-ui.button :href="route('panel.attendances.index', ['data' => $proximo])" variant="secondary" class="btn--icon"><x-icon name="chevron-right" label="Próximo dia" /></x-ui.button>
        </div>
        <x-ui.input name="data" label="Dia" type="date" :value="$date" class="control--date" />
        <x-ui.button type="submit" variant="secondary">Ver</x-ui.button>
    </form>

    <section class="board" aria-labelledby="em-andamento">
        <header class="board__head"><h2 class="title" id="em-andamento">@if ($open->isNotEmpty())<span class="live-dot" aria-hidden="true"></span> @endif Em andamento ({{ $open->count() }})</h2></header>
        @if ($open->isEmpty())
            <x-ui.empty-state compact icon="receipt" title="Nenhum atendimento aberto neste dia.">Quando o cliente chegar, abra pela agenda ou pelo encaixe.</x-ui.empty-state>
        @else
            <ul class="now-list" role="list">
                @foreach ($open as $a)
                    <li>
                        <a class="now-item" href="{{ route('panel.attendances.show', $a) }}">
                            <span class="now-item__since">
                                <span class="text-xs text-muted">aberto</span>
                                <span class="figure figure--sm">{{ BusinessTime::formatLocal($a->opened_at, 'H:i') }}</span>
                            </span>
                            <span class="now-item__who">
                                <strong>{{ $a->customer_name }}</strong>
                                <span class="text-muted">{{ $a->professional_name }} · {{ $a->source->label() }} · {{ $a->status->label() }} · {{ $a->code }}</span>
                            </span>
                            <span class="now-item__go">Abrir comanda <x-icon name="arrow-right" /></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="stack stack-sm" aria-labelledby="encerrados">
        <h2 class="title" id="encerrados">Concluídos e cancelados ({{ $done->count() }})</h2>
        @if ($done->isEmpty())
            <p class="text-sm text-muted">Nada encerrado neste dia.</p>
        @else
            <x-ui.table caption="Atendimentos encerrados" caption-hidden stacked>
                <thead><tr><th scope="col">Código</th><th scope="col">Cliente</th><th scope="col">Profissional</th><th scope="col">Situação</th><th scope="col">Total</th></tr></thead>
                <tbody>
                    @foreach ($done as $a)
                        <tr>
                            <td data-label="Código"><a href="{{ route('panel.attendances.show', $a) }}">{{ $a->code }}</a></td>
                            <td data-label="Cliente">{{ $a->customer_name }}</td>
                            <td data-label="Profissional">{{ $a->professional_name ?? '—' }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$cor($a->status)">{{ $a->status->label() }}</x-ui.badge></td>
                            <td data-label="Total" class="numeric">{{ $a->total_cents !== null ? Money::fromCents($a->total_cents)->format() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </section>
</x-layouts.staff>

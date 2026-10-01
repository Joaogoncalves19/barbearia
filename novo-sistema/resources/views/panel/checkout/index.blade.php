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
            <x-ui.button :href="route('panel.attendances.create')" icon="plus">Encaixe (sem agendamento)</x-ui.button>
        @endif
    </header>

    <p class="text-sm text-muted">Cliente agendado chegou? Abra o atendimento pela <a href="{{ route('panel.agenda', ['data' => $date]) }}">agenda</a>, no agendamento dele.</p>

    <form method="GET" action="{{ route('panel.attendances.index') }}" class="agenda-toolbar-row">
        <x-ui.button :href="route('panel.attendances.index', ['data' => $anterior])" variant="secondary" size="sm" icon="chevron-left">Dia anterior</x-ui.button>
        <x-ui.input name="data" label="Dia" type="date" :value="$date" class="control--date" />
        <x-ui.button type="submit" variant="secondary" size="sm">Ver</x-ui.button>
        <x-ui.button :href="route('panel.attendances.index', ['data' => $proximo])" variant="secondary" size="sm" icon-right="chevron-right">Próximo dia</x-ui.button>
    </form>

    <section class="stack stack-sm" aria-labelledby="em-andamento">
        <h2 class="h3" id="em-andamento">Em andamento ({{ $open->count() }})</h2>
        @if ($open->isEmpty())
            <p class="text-sm text-muted">Nenhum atendimento aberto neste dia.</p>
        @else
            <ol class="appt-list">
                @foreach ($open as $a)
                    <li>
                        <a class="agenda-item" href="{{ route('panel.attendances.show', $a) }}">
                            <span class="agenda-item__time">{{ BusinessTime::formatLocal($a->opened_at, 'H:i') }}<span>{{ $a->code }}</span></span>
                            <span class="agenda-item__body">
                                <strong>{{ $a->customer_name }}</strong>
                                <span class="text-sm text-muted">{{ $a->professional_name }} · {{ $a->source->label() }}</span>
                                <span><x-ui.badge :variant="$cor($a->status)">{{ $a->status->label() }}</x-ui.badge></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="stack stack-sm" aria-labelledby="encerrados">
        <h2 class="h3" id="encerrados">Concluídos e cancelados ({{ $done->count() }})</h2>
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

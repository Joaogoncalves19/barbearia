@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff title="Agenda">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Agenda</h1>
            <p class="text-muted">{{ ucfirst($dayLabel) }}@if ($date === $today) · hoje @endif</p>
        </div>
        @if (auth('web')->user()->can('appointments.manage') || auth('web')->user()->can('appointments.manage_own'))
            <x-ui.button :href="route('panel.appointments.create', ['data' => $date])" icon="calendar-plus">Novo agendamento</x-ui.button>
        @endif
    </header>

    <form method="GET" action="{{ route('panel.agenda') }}" class="agenda-toolbar-row">
        <x-ui.button :href="route('panel.agenda', array_filter(['data' => $prev, 'profissional' => $filter]))" variant="secondary" size="sm" icon="chevron-left">Dia anterior</x-ui.button>
        <x-ui.input name="data" label="Dia" type="date" :value="$date" class="control--date" />
        @if ($allProfessionals->isNotEmpty())
            <x-ui.select name="profissional" label="Profissional" :options="['' => 'Todos'] + $allProfessionals->pluck('display_name', 'id')->all()" :value="$filter" optional />
        @endif
        <x-ui.checkbox name="cancelados" label="Mostrar cancelados" :checked="$showCancelled" />
        <x-ui.button type="submit" variant="secondary" size="sm">Ver</x-ui.button>
        <x-ui.button :href="route('panel.agenda', array_filter(['data' => $next, 'profissional' => $filter]))" variant="secondary" size="sm" icon-right="chevron-right">Próximo dia</x-ui.button>
    </form>

    @if ($professionals->isEmpty())
        <x-ui.empty-state title="Nenhuma agenda para mostrar" icon="calendar">
            Não há profissional ativo ligado à sua conta.
        </x-ui.empty-state>
    @else
        <div class="agenda-day">
            @foreach ($professionals as $p)
                @php
                    $itens = collect($appointments->get($p->id, collect()))->map(fn ($a) => ['type' => 'appt', 'start' => $a->starts_at, 'model' => $a])
                        ->concat($blocks->filter(fn ($b) => $b->professional_id === null || $b->professional_id === $p->id)->map(fn ($b) => ['type' => 'block', 'start' => $b->starts_at, 'model' => $b]))
                        ->sortBy(fn ($i) => $i['start']->getTimestamp())->values();
                @endphp
                <x-ui.card :title="$p->display_name">
                    @if (in_array($p->id, $offToday, true))
                        <x-ui.badge variant="warning">De folga</x-ui.badge>
                    @endif
                    @if ($itens->isEmpty())
                        <p class="text-sm text-muted">Nenhum agendamento neste dia.</p>
                    @else
                        <ol class="appt-list">
                            @foreach ($itens as $i)
                                @if ($i['type'] === 'appt')
                                    @php $a = $i['model']; @endphp
                                    <li>
                                        <a @class(['agenda-item', 'is-cancelled' => $a->status->value === 'cancelled']) href="{{ route('panel.appointments.show', $a) }}">
                                            <span class="agenda-item__time">
                                                {{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}
                                                <span>até {{ BusinessTime::formatLocal($a->ends_at, 'H:i') }}</span>
                                            </span>
                                            <span class="agenda-item__body">
                                                <strong>{{ $a->customer_name }}</strong>
                                                <span class="text-sm text-muted">{{ $a->items->pluck('name')->join(', ') }} · {{ (int) $a->starts_at->diffInMinutes($a->ends_at) }} min</span>
                                                <span><x-ui.badge :variant="match ($a->status->value) { 'confirmed' => 'success', 'pending' => 'warning', 'cancelled' => 'neutral', 'no_show' => 'danger', default => 'info' }">{{ $a->status->label() }}</x-ui.badge></span>
                                            </span>
                                        </a>
                                    </li>
                                @else
                                    @php $b = $i['model']; @endphp
                                    <li class="agenda-item is-block">
                                        <span class="agenda-item__time">
                                            {{ BusinessTime::formatLocal($b->starts_at, BusinessTime::dateOf($b->starts_at) === $date ? 'H:i' : 'd/m H:i') }}
                                            <span>até {{ BusinessTime::formatLocal($b->ends_at, BusinessTime::dateOf($b->ends_at) === $date ? 'H:i' : 'd/m H:i') }}</span>
                                        </span>
                                        <span class="agenda-item__body">
                                            <strong>Bloqueado{{ $b->professional_id === null ? ' (barbearia)' : '' }}</strong>
                                            <span class="text-sm">{{ $b->reason }}</span>
                                        </span>
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-layouts.staff>

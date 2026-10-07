{{--
    ATENDIMENTOS do profissional (Fase 12.5): os dele, por dia, ou a busca
    pelo nome do cliente (historico). So os proprios (AttendanceController).
--}}
@php
    use App\Modules\Checkout\Enums\AttendanceStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—';
    $buscando = $search !== '';
@endphp
<x-layouts.professional title="Meus atendimentos">
    <header class="pro-head">
        <div class="pro-head__text">
            <p class="eyebrow">Meus atendimentos</p>
            <h1 class="pro-head__title">{{ $buscando ? 'Busca: '.$search : \Illuminate\Support\Str::ucfirst($dayLabel) }}</h1>
        </div>
        @if ($canWalkIn)
            <div class="pro-head__actions">
                <x-ui.button :href="route('panel.attendances.create')" variant="secondary" icon="armchair">Encaixe</x-ui.button>
            </div>
        @endif
    </header>

    @include('professional.partials.errors')

    <div class="day-bar">
        @unless ($buscando)
            <div class="cluster">
                <x-ui.button :href="route('pro.attendances', ['data' => $prev])" variant="secondary" size="sm" icon="chevron-left">Dia anterior</x-ui.button>
                @if ($date !== $today)<x-ui.button :href="route('pro.attendances')" variant="ghost" size="sm">Hoje</x-ui.button>@endif
                <x-ui.button :href="route('pro.attendances', ['data' => $next])" variant="secondary" size="sm" icon-right="chevron-right">Próximo dia</x-ui.button>
            </div>
        @else
            <x-ui.button :href="route('pro.attendances')" variant="secondary" size="sm" icon="arrow-left">Voltar para hoje</x-ui.button>
        @endunless
        <form method="GET" action="{{ route('pro.attendances') }}" class="day-bar__jump" role="search">
            <x-ui.input name="busca" type="search" label="Buscar cliente" :value="$search" hint="Pelo nome, nos seus atendimentos." optional />
            <x-ui.button type="submit" variant="secondary" size="sm" icon="search">Buscar</x-ui.button>
        </form>
    </div>

    @if ($attendances->isEmpty())
        <x-ui.empty-state compact :title="$buscando ? 'Nenhum atendimento seu com esse nome' : 'Nenhum atendimento neste dia'" icon="receipt" />
    @else
        <ol class="timeline-pro" role="list">
            @foreach ($attendances as $at)
                @php
                    $tom = match ($at->status) { AttendanceStatus::InProgress => 'live', AttendanceStatus::Completed => 'done', AttendanceStatus::Cancelled => 'off', default => 'open' };
                    $cor = match ($at->status->value) { 'open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', default => 'neutral' };
                @endphp
                <li class="line line--{{ $tom }}">
                    <a class="line__link" href="{{ route('pro.attendances.show', $at) }}">
                        <span class="line__time">
                            <span class="figure">{{ BusinessTime::formatLocal($at->opened_at, 'H:i') }}</span>
                            <span class="line__end">{{ $buscando ? BusinessTime::formatLocal($at->opened_at, 'd/m/Y') : $at->source->label() }}</span>
                        </span>
                        <span class="line__body">
                            <strong class="line__who">{{ $at->customer_name }}</strong>
                            <span class="line__what">{{ $at->items->pluck('name')->join(', ') ?: 'Sem itens' }}</span>
                            <span class="line__flags"><x-ui.badge :variant="$cor">{{ $at->status->label() }}</x-ui.badge>
                                @if ($at->status === AttendanceStatus::Completed)<span class="numeric text-sm">{{ $fmt($at->total_cents) }}@if ((int) $at->tip_cents > 0) · gorjeta {{ $fmt($at->tip_cents) }}@endif</span>@endif</span>
                        </span>
                        <x-icon name="chevron-right" class="line__go" />
                    </a>
                </li>
            @endforeach
        </ol>
        @if ($buscando && method_exists($attendances, 'links'))
            {{ $attendances->links() }}
        @endif
    @endif
</x-layouts.professional>

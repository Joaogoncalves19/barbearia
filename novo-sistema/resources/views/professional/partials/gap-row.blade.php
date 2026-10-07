{{--
    Linha que nao e agendamento: tempo livre (Availability::dayOverview),
    pausa ou bloqueio. Espera $kind (free|break|block), $start, $end e, para o
    livre, $bookUrl (null se nao puder agendar).
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Duration;
    $minutos = (int) $start->diffInMinutes($end);
    $rotulo = match ($kind) { 'free' => 'Livre', 'break' => 'Pausa', default => 'Bloqueado' };
@endphp
<li class="line line--{{ $kind }}">
    <div class="line__row">
        <span class="line__time">
            <span class="figure">{{ BusinessTime::formatLocal($start, 'H:i') }}</span>
            <span class="line__end">até {{ BusinessTime::formatLocal($end, 'H:i') }}</span>
        </span>
        <span class="line__body">
            <strong class="line__who">{{ $rotulo }}</strong>
            <span class="line__what">{{ Duration::format($minutos) }}@if (! empty($reason)) · {{ $reason }}@endif</span>
        </span>
        @if ($kind === 'free' && ! empty($bookUrl))
            <a class="btn btn--ghost btn--sm line__action" href="{{ $bookUrl }}"><x-icon name="calendar-plus" /><span>Agendar<span class="visually-hidden"> às {{ BusinessTime::formatLocal($start, 'H:i') }}</span></span></a>
        @endif
    </div>
</li>

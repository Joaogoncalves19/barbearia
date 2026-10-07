{{--
    Uma linha da linha do tempo do profissional: um agendamento com a duracao
    real e o estado em palavras (sinais de leitura, nenhuma regra nova).
    Espera $a (Appointment com items e attendance) e $now.
--}}
@php
    use App\Modules\Checkout\Enums\AttendanceStatus;
    use App\Modules\Scheduling\Enums\AppointmentSource;
    use App\Modules\Scheduling\Enums\AppointmentStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Duration;

    $at = $a->attendance;
    $minutos = $a->starts_at && $a->ends_at ? (int) $a->starts_at->diffInMinutes($a->ends_at) : 0;
    [$estado, $cor] = match (true) {
        $at?->status === AttendanceStatus::InProgress => ['Em atendimento', 'info'],
        $at?->status === AttendanceStatus::Open => ['Cliente chegou', 'accent'],
        $a->status === AppointmentStatus::Completed => ['Concluído', 'success'],
        $a->status === AppointmentStatus::NoShow => ['Faltou', 'danger'],
        $a->status === AppointmentStatus::Cancelled => ['Cancelado', 'neutral'],
        $a->status === AppointmentStatus::Pending => ['A confirmar', 'warning'],
        default => [$a->status->label(), 'neutral'],
    };
    $atrasado = $a->status->isOpen() && $at === null && $a->starts_at !== null && $a->starts_at->lt($now)
        && BusinessTime::dateOf($a->starts_at) === BusinessTime::today();
    $tom = match (true) {
        $at?->status === AttendanceStatus::InProgress => 'live',
        in_array($a->status, [AppointmentStatus::Completed], true) => 'done',
        in_array($a->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true) => 'off',
        default => 'open',
    };
@endphp
<li class="line line--{{ $tom }}">
    <a class="line__link" href="{{ route('pro.appointments.show', $a) }}">
        <span class="line__time">
            <span class="figure">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
            <span class="line__end">até {{ BusinessTime::formatLocal($a->ends_at, 'H:i') }}</span>
        </span>
        <span class="line__body">
            <strong class="line__who">{{ $a->customer_name }}</strong>
            <span class="line__what">{{ $a->items->pluck('name')->join(', ') ?: 'Serviço' }} · {{ Duration::format($minutos) }}</span>
            <span class="line__flags">
                <x-ui.badge :variant="$cor">{{ $estado }}</x-ui.badge>
                @if ($atrasado)<x-ui.badge variant="danger">Atrasado</x-ui.badge>@endif
                @if ($a->source === AppointmentSource::WalkIn)<x-ui.badge variant="accent">Encaixe</x-ui.badge>@endif
            </span>
        </span>
        <x-icon name="chevron-right" class="line__go" />
    </a>
</li>

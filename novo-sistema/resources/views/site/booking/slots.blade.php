@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $proSlug = $professional?->slug ?? 'qualquer';
@endphp
<x-layouts.booking :title="'Horários: '.$service->name" :step="3">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('booking.professional', $service) }}">Trocar profissional</a>
        <h1 class="h2">Escolha o dia e o horário</h1>
        <p class="text-muted">{{ $service->name }} ({{ $service->durationLabel() }}) · {{ $professional?->display_name ?? 'Sem preferência de profissional' }}</p>
    </header>

    @if ($days === [])
        <x-ui.empty-state title="Sem dias disponíveis" icon="calendar">A barbearia não tem horário aberto para agendamento pelo site agora.</x-ui.empty-state>
    @else
        @include('partials.day-picker', [
            'days' => $days, 'date' => $date,
            'url' => fn ($d) => route('booking.slots', ['service' => $service, 'profissional' => $proSlug, 'data' => $d]),
        ])

        <section class="stack stack-sm" aria-labelledby="horarios-titulo">
            <h2 class="h3" id="horarios-titulo">
                Horários em {{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $date, BusinessTime::zone())->locale('pt_BR')->translatedFormat('d/m (l)') }}
            </h2>
            @if ($slots === [])
                <x-ui.empty-state title="Nenhum horário livre neste dia" icon="clock">Escolha outro dia acima.</x-ui.empty-state>
            @else
                <div class="slots">
                    @foreach ($slots as $s)
                        @php $hora = BusinessTime::local($s['start'])->format('H:i'); @endphp
                        <a class="slot" href="{{ route('account.booking.confirm', ['servico' => $service->slug, 'profissional' => $proSlug, 'data' => $date, 'hora' => $hora]) }}"
                           aria-label="{{ $hora }}{{ $professional ? '' : ' com '.$s['professionals'][0]->display_name }}">
                            {{ $hora }}
                        </a>
                    @endforeach
                </div>
                <p class="text-sm text-muted">Ao escolher, você confirma na próxima tela (com login).</p>
            @endif
        </section>
    @endif
</x-layouts.booking>

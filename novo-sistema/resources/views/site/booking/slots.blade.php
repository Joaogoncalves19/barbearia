@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $proSlug = $professional?->slug ?? 'qualquer';
@endphp
<x-layouts.booking :title="'Horários: '.$service->name" :step="3">
    <header class="booking-head">
        <a class="link-arrow back-link" href="{{ route('booking.professional', $service) }}"><x-icon name="chevron-left" /> Trocar profissional</a>
        <h1 class="h1 caps">Escolha o dia e o horário</h1>
        <p class="booking-pick"><strong>{{ $service->name }}</strong> <span>{{ $service->durationLabel() }}</span> <span>{{ $professional?->display_name ?? 'Sem preferência de profissional' }}</span></p>
    </header>

    @if ($days === [])
        <x-ui.empty-state title="Sem dias disponíveis" icon="calendar">A barbearia não tem horário aberto para agendamento pelo site agora.</x-ui.empty-state>
    @else
        @include('partials.day-picker', [
            'days' => $days, 'date' => $date,
            'url' => fn ($d) => route('booking.slots', ['service' => $service, 'profissional' => $proSlug, 'data' => $d]),
        ])

        <section class="stack stack-sm" aria-labelledby="horarios-titulo">
            <h2 class="eyebrow eyebrow--plain" id="horarios-titulo">
                Horários em {{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $date, BusinessTime::zone())->locale('pt_BR')->translatedFormat('d/m (l)') }}
            </h2>
            @if ($slots === [])
                @php $proximo = collect($days)->first(fn ($d) => $d > $date); @endphp
                <x-ui.empty-state title="Nenhum horário livre neste dia" icon="clock">
                    Escolha outro dia acima{{ $proximo ? ' ou veja o próximo dia aberto' : '' }}.
                    @if ($proximo)
                        <x-slot:action>
                            <x-ui.button :href="route('booking.slots', ['service' => $service, 'profissional' => $proSlug, 'data' => $proximo])" variant="secondary" icon-right="arrow-right">Ver {{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $proximo, BusinessTime::zone())->locale('pt_BR')->translatedFormat('l, d/m') }}</x-ui.button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
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

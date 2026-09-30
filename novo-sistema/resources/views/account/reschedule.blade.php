@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.account title="Remarcar {{ $appointment->code }}">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.appointments.show', $appointment) }}">Voltar para o horário</a>
        <h1 class="h2">Remarcar</h1>
        <p class="text-muted">Hoje: {{ BusinessTime::formatLocal($appointment->starts_at, 'd/m/Y \à\s H:i') }} com {{ $appointment->professional_name }}. O serviço e o valor continuam os mesmos.</p>
    </header>

    @error('slot')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($service === null || $professional === null)
        <x-ui.empty-state title="Não é possível remarcar por aqui" icon="calendar">Fale com a barbearia para remarcar este horário.</x-ui.empty-state>
    @else
        @if ($professionals->count() > 1)
            <div class="cluster" role="group" aria-label="Profissional">
                @foreach ($professionals as $p)
                    <x-ui.button :href="route('account.appointments.reschedule', ['appointment' => $appointment, 'profissional' => $p->slug, 'data' => $date])" :variant="$p->is($professional) ? 'accent' : 'secondary'" size="sm" :aria-current="$p->is($professional) ? 'true' : null">{{ $p->display_name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        @include('partials.day-picker', [
            'days' => $days, 'date' => $date,
            'url' => fn ($d) => route('account.appointments.reschedule', ['appointment' => $appointment, 'profissional' => $professional->slug, 'data' => $d]),
        ])

        <form method="POST" action="{{ route('account.appointments.reschedule.update', $appointment) }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="data" value="{{ $date }}">
            <input type="hidden" name="profissional" value="{{ $professional->slug }}">
            <fieldset class="check-group">
                <legend>Novo horário com {{ $professional->display_name }}</legend>
                @if ($slots === [])
                    <p class="text-muted">Nenhum horário livre neste dia. Escolha outro dia.</p>
                @else
                    <div class="slots">
                        @foreach ($slots as $s)
                            @php $hora = BusinessTime::local($s['start'])->format('H:i'); @endphp
                            <label class="slot"><input type="radio" name="hora" value="{{ $hora }}" required> {{ $hora }}</label>
                        @endforeach
                    </div>
                @endif
            </fieldset>
            @if ($slots !== [])
                <div><x-ui.button type="submit" variant="accent">Remarcar para o horário escolhido</x-ui.button></div>
            @endif
        </form>
    @endif
</x-layouts.account>

@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff :title="'Remarcar '.$appointment->code">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.appointments.show', $appointment) }}">Voltar para o agendamento</a>
            <h1 class="page-head__title">Remarcar {{ $appointment->customer_name }}</h1>
            <p class="text-muted">Hoje: {{ BusinessTime::formatLocal($appointment->starts_at, 'd/m/Y H:i') }} com {{ $appointment->professional_name }}. Serviço, valor e duração continuam os mesmos.</p>
        </div>
    </header>

    @error('slot')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($service === null || $professional === null)
        <x-ui.empty-state title="Não há como remarcar" icon="calendar">O serviço deste agendamento não está mais disponível ou nenhum profissional que você possa agendar o executa. Cancele e crie um novo, se for o caso.</x-ui.empty-state>
    @else
        <x-ui.card>
            <form method="GET" action="{{ route('panel.appointments.reschedule', $appointment) }}" class="agenda-toolbar-row">
                <input type="hidden" name="data" value="{{ $date }}">
                <x-ui.select name="profissional" label="Profissional" :value="$professional->id" :options="$professionals->pluck('display_name', 'id')->all()" />
                <x-ui.button type="submit" variant="secondary" size="sm">Ver horários</x-ui.button>
            </form>
        </x-ui.card>

        @include('partials.day-picker', [
            'days' => $days, 'date' => $date,
            'url' => fn ($d) => route('panel.appointments.reschedule', ['appointment' => $appointment, 'profissional' => $professional->id, 'data' => $d]),
        ])

        <form method="POST" action="{{ route('panel.appointments.reschedule.update', $appointment) }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="data" value="{{ $date }}">
            <input type="hidden" name="professional_id" value="{{ $professional->id }}">
            <x-ui.card>
                <fieldset class="check-group">
                    <legend>Horários livres de {{ $professional->display_name }}</legend>
                    @if ($slots === [])
                        <p class="text-muted">Nenhum horário livre neste dia.</p>
                    @else
                        <div class="slots">
                            @foreach ($slots as $s)
                                @php $hora = BusinessTime::local($s['start'])->format('H:i'); @endphp
                                <label class="slot"><input type="radio" name="hora" value="{{ $hora }}" required> {{ $hora }}</label>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
            </x-ui.card>
            @if ($slots !== [])
                <div><x-ui.button type="submit">Remarcar</x-ui.button></div>
            @endif
        </form>
    @endif
</x-layouts.staff>

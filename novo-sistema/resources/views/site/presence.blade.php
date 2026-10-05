{{-- Confirmacao de presenca pelo link do lembrete (Fase 10, D-51). Sem login; so o primeiro nome. --}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $primeiro = trim(explode(' ', trim((string) $appointment->customer_name))[0] ?? '');
@endphp
<x-layouts.auth title="Confirmar presença" area="site">
    <header class="stack stack-sm">
        <h1 class="h2">Confirmar presença</h1>
        @if ($primeiro !== '')<p class="text-muted">Olá, {{ $primeiro }}.</p>@endif
    </header>

    @if (session('error'))
        <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
    @endif

    <dl class="summary-list">
        <div><dt>Quando</dt><dd>{{ $appointment->starts_at ? BusinessTime::formatLocal($appointment->starts_at, 'd/m/Y H:i') : '—' }}</dd></div>
        <div><dt>Profissional</dt><dd>{{ $appointment->professional_name ?? 'A definir' }}</dd></div>
        <div><dt>Código</dt><dd>{{ $appointment->code }}</dd></div>
    </dl>

    @if ($appointment->presence_confirmed_at !== null)
        <x-ui.alert variant="success" title="Presença confirmada">Esperamos você no horário.</x-ui.alert>
    @elseif ($open)
        <form method="POST" action="{{ request()->fullUrl() }}" class="stack">
            @csrf
            <x-ui.button type="submit" variant="accent" icon="circle-check" block>Confirmar que vou</x-ui.button>
        </form>
        <p class="text-sm text-muted">Não vai poder vir? Remarque ou cancele pela sua conta, dentro do prazo, para liberar o horário.</p>
    @else
        <x-ui.alert variant="info">Este agendamento não está mais ativo.</x-ui.alert>
    @endif
</x-layouts.auth>

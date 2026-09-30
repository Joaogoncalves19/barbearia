@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $podeRemarcar = auth('customer')->user()->can('reschedule', $appointment);
    $podeCancelar = auth('customer')->user()->can('cancel', $appointment);
@endphp
<x-layouts.account title="Horário {{ $appointment->code }}">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.home') }}">Voltar para minha conta</a>
        <h1 class="h2">Horário {{ $appointment->code }}</h1>
    </header>

    @error('appointment')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card>
        <dl class="summary-list">
            <div><dt>Quando</dt><dd>{{ $appointment->starts_at ? BusinessTime::formatLocal($appointment->starts_at, 'd/m/Y \à\s H:i') : '—' }}</dd></div>
            <div><dt>Profissional</dt><dd>{{ $appointment->professional_name ?? 'A definir' }}</dd></div>
            <div><dt>Situação</dt><dd>{{ $appointment->status->label() }}</dd></div>
            @foreach ($appointment->items as $item)
                <div><dt>{{ $item->name }}</dt><dd>{{ $item->total_cents !== null ? \App\Modules\Shared\Support\Money::fromCents((int) $item->total_cents)->format() : '—' }}</dd></div>
            @endforeach
        </dl>
    </x-ui.card>

    @if ($podeRemarcar || $podeCancelar)
        <div class="cluster">
            @if ($podeRemarcar)
                <x-ui.button :href="route('account.appointments.reschedule', $appointment)" variant="secondary" icon="calendar">Remarcar</x-ui.button>
            @endif
            @if ($podeCancelar)
                <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-agendamento">Cancelar agendamento</x-ui.button>
                <x-ui.confirm id="cancelar-agendamento" title="Cancelar este horário?" :action="route('account.appointments.cancel', $appointment)" confirm-label="Cancelar agendamento">
                    <p>O horário de {{ BusinessTime::formatLocal($appointment->starts_at, 'd/m \à\s H:i') }} fica livre para outra pessoa.</p>
                </x-ui.confirm>
            @endif
        </div>
        <p class="text-sm text-muted">
            Pela conta, você cancela ou remarca até {{ intdiv($policy->int('customer_cancel_notice_minutes'), 60) > 0 ? intdiv($policy->int('customer_cancel_notice_minutes'), 60).' h' : $policy->int('customer_cancel_notice_minutes').' min' }} antes.
            Depois disso, fale com a barbearia.
        </p>
    @endif
</x-layouts.account>

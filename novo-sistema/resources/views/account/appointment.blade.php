@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $podeRemarcar = auth('customer')->user()->can('reschedule', $appointment);
    $podeCancelar = auth('customer')->user()->can('cancel', $appointment);
@endphp
<x-layouts.account title="Horário {{ $appointment->code }}">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.appointments.index') }}">Voltar para agendamentos</a>
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
            {{-- Fase 8: o desconto combinado e o total gravados no agendamento (o mesmo valor da confirmacao). --}}
            @foreach ($appointment->adjustments as $ajuste)
                <div><dt>Desconto · {{ $ajuste->kind->label() }}</dt><dd class="numeric">−{{ \App\Modules\Shared\Support\Money::fromCents((int) $ajuste->amount_cents)->format() }}</dd></div>
            @endforeach
            @if ($appointment->total_cents !== null)
                <div><dt>Total</dt><dd class="numeric" data-total>{{ \App\Modules\Shared\Support\Money::fromCents((int) $appointment->total_cents)->format() }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($appointment->attendance !== null && auth('customer')->user()->can('view', $appointment->attendance))
        <x-ui.button :href="route('account.attendances.show', $appointment->attendance)" variant="secondary" icon="receipt">Ver comprovante do atendimento</x-ui.button>
    @endif

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
        @php
            $prazo = fn (int $min) => intdiv($min, 60) > 0 && $min % 60 === 0 ? intdiv($min, 60).' h' : $min.' min';
        @endphp
        <p class="text-sm text-muted" data-change-rules>
            Pela conta, você cancela até {{ $prazo($policy->int('customer_cancel_notice_minutes')) }} antes e remarca até {{ $prazo($policy->int('customer_reschedule_notice_minutes')) }} antes,
            no máximo {{ $policy->int('customer_max_reschedules') }} {{ $policy->int('customer_max_reschedules') === 1 ? 'vez' : 'vezes' }} por horário (usadas: {{ (int) $appointment->customer_reschedules }}).
            Depois disso, fale com a barbearia.
        </p>
    @endif
</x-layouts.account>

@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $u = auth('web')->user();
    $a = $appointment;
@endphp
<x-layouts.staff :title="'Agendamento '.$a->code">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.agenda', ['data' => BusinessTime::dateOf($a->starts_at)]) }}">Voltar para a agenda do dia</a>
            <h1 class="page-head__title">{{ $a->customer_name }}</h1>
            <p><x-ui.badge :variant="match ($a->status->value) { 'confirmed' => 'success', 'pending' => 'warning', 'no_show' => 'danger', default => 'neutral' }">{{ $a->status->label() }}</x-ui.badge> <span class="text-muted">{{ $a->code }}</span></p>
        </div>
    </header>

    @error('appointment')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <div class="dashboard-grid">
        <x-ui.card title="Atendimento">
            <dl class="summary-list">
                <div><dt>Quando</dt><dd>{{ BusinessTime::formatLocal($a->starts_at, 'd/m/Y H:i') }} – {{ BusinessTime::formatLocal($a->ends_at, 'H:i') }}</dd></div>
                <div><dt>Profissional</dt><dd>{{ $a->professional_name }}</dd></div>
                @foreach ($a->items as $item)
                    <div><dt>{{ $item->name }}</dt><dd>{{ $item->total_cents !== null ? \App\Modules\Shared\Support\Money::fromCents((int) $item->total_cents)->format() : '—' }}</dd></div>
                @endforeach
                <div><dt>Origem</dt><dd>{{ $a->source->label() }}</dd></div>
                <div><dt>Telefone</dt><dd>{{ $a->customer_phone ?? '—' }}</dd></div>
                @if ($a->cancellation_reason)<div><dt>Motivo do cancelamento</dt><dd>{{ $a->cancellation_reason }}</dd></div>@endif
            </dl>
            <p class="text-sm text-muted">Valores registrados ao agendar: mudanças no catálogo não alteram este atendimento.</p>
        </x-ui.card>

        <x-ui.card title="Ações">
            <div class="stack">
                @if ($a->attendance !== null)
                    @can('view', $a->attendance)
                        <x-ui.button :href="route('panel.attendances.show', $a->attendance)" icon="receipt">Atendimento {{ $a->attendance->code }} · {{ $a->attendance->status->label() }}</x-ui.button>
                    @endcan
                @elseif (in_array($a->status->value, ['pending', 'confirmed'], true) && BusinessTime::dateOf($a->starts_at) === BusinessTime::today() && $a->professional && $u->can('openFor', [\App\Modules\Checkout\Models\Attendance::class, $a->professional]))
                    <form method="POST" action="{{ route('panel.attendances.open', $a) }}">
                        @csrf
                        <x-ui.button type="submit" icon="play">Cliente chegou: abrir atendimento</x-ui.button>
                    </form>
                @endif
                <div class="cluster">
                    @if ($a->status->value === 'pending')
                        @can('update', $a)
                            <form method="POST" action="{{ route('panel.appointments.confirm', $a) }}">@csrf<x-ui.button type="submit" icon="check">Confirmar</x-ui.button></form>
                        @endcan
                    @endif
                    @if ($a->status->isOpen())
                        @can('reschedule', $a)
                            <x-ui.button :href="route('panel.appointments.reschedule', $a)" variant="secondary" icon="calendar">Remarcar</x-ui.button>
                        @endcan
                    @endif
                    @if ($a->status->value === 'confirmed' && $a->starts_at->lte(BusinessTime::now()))
                        @can('update', $a)
                            <x-ui.button variant="secondary" icon="circle-x" data-dialog-open="marcar-falta">Não compareceu</x-ui.button>
                            <x-ui.confirm id="marcar-falta" title="Registrar falta?" :action="route('panel.appointments.no-show', $a)" confirm-label="Registrar falta">
                                <p>{{ $a->customer_name }} não compareceu ao horário de {{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}.</p>
                            </x-ui.confirm>
                        @endcan
                    @endif
                    @if ($a->status->canTransitionTo(\App\Modules\Scheduling\Enums\AppointmentStatus::Cancelled))
                        @can('cancel', $a)
                            <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-agendamento">Cancelar</x-ui.button>
                        @endcan
                    @endif
                </div>

                @can('update', $a)
                    <form method="POST" action="{{ route('panel.appointments.notes', $a) }}" class="stack stack-sm">
                        @csrf
                        @method('PUT')
                        <x-ui.textarea name="notes" label="Observações" :value="$a->notes" rows="2" optional />
                        <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar observações</x-ui.button></div>
                    </form>
                @else
                    @if ($a->notes)<p class="text-sm"><strong>Observações:</strong> {{ $a->notes }}</p>@endif
                @endcan
            </div>
        </x-ui.card>
    </div>

    @if ($a->status->canTransitionTo(\App\Modules\Scheduling\Enums\AppointmentStatus::Cancelled))
        @can('cancel', $a)
            <dialog id="cancelar-agendamento" class="dialog" aria-labelledby="cancelar-agendamento-titulo">
                <div class="dialog__panel">
                    <header class="dialog__header">
                        <h2 class="title" id="cancelar-agendamento-titulo">Cancelar este agendamento?</h2>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" data-dialog-close><x-icon name="x" label="Fechar" /></button>
                    </header>
                    <form method="POST" action="{{ route('panel.appointments.cancel', $a) }}">
                        @csrf
                        <div class="dialog__body stack">
                            <p>O horário fica livre. O registro continua no histórico.</p>
                            <x-ui.input name="reason" label="Motivo" id="campo-motivo-cancelamento" optional />
                        </div>
                        <footer class="dialog__footer">
                            <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                            <button type="submit" class="btn btn--danger">Cancelar agendamento</button>
                        </footer>
                    </form>
                </div>
            </dialog>
        @endcan
    @endif

    <x-ui.card title="Histórico">
        <ol class="timeline">
            @foreach ($a->events as $e)
                <li>
                    <span class="text-sm text-muted">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m/Y H:i') : '' }} · {{ $e->actor_label }}</span>
                    <span>{{ $e->description }}</span>
                    @if (! empty($e->data['de']))<span class="text-sm text-muted">De {{ $e->data['de'] }} para {{ $e->data['para'] }}</span>@endif
                    @if (! empty($e->data['motivo']))<span class="text-sm text-muted">Motivo: {{ $e->data['motivo'] }}</span>@endif
                </li>
            @endforeach
        </ol>
    </x-ui.card>
</x-layouts.staff>

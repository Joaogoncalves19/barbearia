{{--
    AGENDAMENTO visto pelo profissional (Fase 12.5): o necessario para atender.
    Acoes pelas rotas do painel (mesmas Policies e regras); voltam para ca.
--}}
@php
    use App\Modules\Checkout\Models\Attendance;
    use App\Modules\Scheduling\Enums\AppointmentSource;
    use App\Modules\Scheduling\Enums\AppointmentStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Duration;
    use App\Modules\Shared\Support\Money;
    $a = $appointment;
    $u = auth('web')->user();
    $minutos = (int) $a->starts_at->diffInMinutes($a->ends_at);
    $hojeEle = BusinessTime::dateOf($a->starts_at) === BusinessTime::today();
    $cor = match ($a->status->value) { 'confirmed' => 'success', 'pending' => 'warning', 'no_show' => 'danger', 'completed' => 'success', default => 'neutral' };
@endphp
<x-layouts.professional :title="'Agendamento · '.$a->customer_name">
    <header class="pro-head">
        <div class="pro-head__text">
            <a class="link-arrow text-sm" href="{{ route('pro.agenda', ['data' => BusinessTime::dateOf($a->starts_at)]) }}"><x-icon name="arrow-left" /> Agenda do dia</a>
            <h1 class="pro-head__title">{{ $a->customer_name }}</h1>
            <p class="cluster">
                <x-ui.badge :variant="$cor">{{ $a->status->label() }}</x-ui.badge>
                @if ($a->source === AppointmentSource::WalkIn)<x-ui.badge variant="accent">Encaixe</x-ui.badge>@endif
                <span class="text-muted text-sm">{{ $a->code }}</span>
            </p>
        </div>
    </header>

    @include('professional.partials.errors')

    <div class="pro-split">
        <div class="stack stack-lg">
            <section class="pro-block" aria-labelledby="quando">
                <h2 id="quando" class="visually-hidden">Horário e serviços</h2>
                <p class="when">
                    <span class="when__time figure figure--lg">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
                    <span class="when__rest">até {{ BusinessTime::formatLocal($a->ends_at, 'H:i') }} · {{ Duration::format($minutos) }}<br>{{ \Illuminate\Support\Str::ucfirst(BusinessTime::local($a->starts_at)->locale('pt_BR')->translatedFormat('l, d/m')) }}</span>
                </p>
                <dl class="summary-list">
                    @foreach ($a->items as $item)
                        <div><dt>{{ $item->name }}</dt><dd class="numeric">{{ $item->total_cents !== null ? Money::fromCents((int) $item->total_cents)->format() : '—' }}</dd></div>
                    @endforeach
                    <div><dt>Telefone</dt><dd>@if ($a->customer_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $a->customer_phone) }}">{{ $a->customer_phone }}</a>@else — @endif</dd></div>
                    <div><dt>Origem</dt><dd>{{ $a->source->label() }}</dd></div>
                    @if ($a->cancellation_reason)<div><dt>Motivo do cancelamento</dt><dd>{{ $a->cancellation_reason }}</dd></div>@endif
                </dl>
            </section>

            {{-- O que fazer agora --}}
            <section class="pro-block pro-actions" aria-labelledby="acoes">
                <h2 id="acoes" class="pro-section-title">O que fazer</h2>
                @if ($a->attendance !== null)
                    @can('view', $a->attendance)
                        <x-ui.button :href="route('pro.attendances.show', $a->attendance)" icon="receipt" size="lg">Atendimento · {{ $a->attendance->status->label() }}</x-ui.button>
                    @endcan
                @elseif (in_array($a->status->value, ['pending', 'confirmed'], true) && $hojeEle && $canOpen)
                    <form method="POST" action="{{ route('panel.attendances.open', $a) }}">
                        @csrf
                        <x-ui.button type="submit" icon="play" size="lg">Cliente chegou: abrir atendimento</x-ui.button>
                    </form>
                @endif
                <div class="cluster">
                    @if ($a->status->value === 'pending')
                        @can('update', $a)
                            <form method="POST" action="{{ route('panel.appointments.confirm', $a) }}">@csrf<x-ui.button type="submit" variant="secondary" icon="check">Confirmar</x-ui.button></form>
                        @endcan
                    @endif
                    @if ($a->status->isOpen() && $a->attendance === null)
                        @can('reschedule', $a)
                            <x-ui.button :href="route('panel.appointments.reschedule', $a)" variant="secondary" icon="calendar">Remarcar</x-ui.button>
                        @endcan
                    @endif
                    @if ($a->status->value === 'confirmed' && $a->attendance === null && $a->starts_at->lte(BusinessTime::now()))
                        @can('update', $a)
                            <x-ui.button variant="secondary" icon="circle-x" data-dialog-open="marcar-falta">Não compareceu</x-ui.button>
                        @endcan
                    @endif
                    @if ($a->status->canTransitionTo(AppointmentStatus::Cancelled) && $a->attendance === null)
                        @can('cancel', $a)
                            <x-ui.button variant="ghost" icon="x" data-dialog-open="cancelar-agendamento">Cancelar</x-ui.button>
                        @endcan
                    @endif
                </div>
                @if (! $a->status->isOpen() && $a->attendance === null)
                    <p class="text-sm text-muted">Nada a fazer: este horário já foi {{ mb_strtolower($a->status->label()) }}.</p>
                @endif
            </section>

            <section class="pro-block" aria-labelledby="obs">
                <h2 id="obs" class="pro-section-title">Observações do agendamento</h2>
                @can('update', $a)
                    <form method="POST" action="{{ route('panel.appointments.notes', $a) }}" class="stack stack-sm">
                        @csrf
                        @method('PUT')
                        <x-ui.textarea name="notes" label="Observações" :value="$a->notes" rows="2" optional />
                        <div><x-ui.button type="submit" variant="secondary" size="sm">Salvar observações</x-ui.button></div>
                    </form>
                @else
                    <p>{{ $a->notes ?: 'Sem observações.' }}</p>
                @endcan
            </section>
        </div>

        <div class="stack stack-lg">
            @if ($customer !== null && $canNote)
                @include('professional.partials.customer-notes', ['customer' => $customer, 'notes' => $notes, 'noteMax' => $noteMax])
            @endif

            <section class="pro-block" aria-labelledby="historico-cliente">
                <h2 id="historico-cliente" class="pro-section-title"><x-icon name="history" /> Atendimentos anteriores com você</h2>
                @if ($customer === null)
                    <p class="text-sm text-muted">Cliente sem cadastro: não há histórico.</p>
                @elseif ($history->isEmpty())
                    <p class="text-sm text-muted">Primeira vez com você.</p>
                @else
                    <ol class="visits" role="list">
                        @foreach ($history as $h)
                            <li class="visit">
                                <p class="visit__date">{{ BusinessTime::formatLocal($h->completed_at, 'd/m/Y') }}</p>
                                <p class="visit__what">{{ $h->items->pluck('name')->join(', ') }}</p>
                                @if ($h->consumptions->isNotEmpty())<p class="visit__used text-sm text-muted">Material: {{ $h->consumptions->map(fn ($c) => $c->quantity.' × '.$c->product_name)->join(', ') }}</p>@endif
                                @if ($h->notes)<p class="visit__used text-sm text-muted">Obs.: {{ $h->notes }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <x-ui.hint summary="Histórico do agendamento">
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
            </x-ui.hint>
        </div>
    </div>

    @if ($a->status->value === 'confirmed' && $a->attendance === null && $a->starts_at->lte(BusinessTime::now()))
        @can('update', $a)
            <x-ui.confirm id="marcar-falta" title="Registrar falta?" :action="route('panel.appointments.no-show', $a)" confirm-label="Registrar falta">
                <p>{{ $a->customer_name }} não compareceu ao horário de {{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}.</p>
            </x-ui.confirm>
        @endcan
    @endif

    @if ($a->status->canTransitionTo(AppointmentStatus::Cancelled) && $a->attendance === null)
        @can('cancel', $a)
            <x-ui.modal id="cancelar-agendamento" title="Cancelar este agendamento?">
                <form method="POST" action="{{ route('panel.appointments.cancel', $a) }}" class="stack" id="form-cancelar-agendamento">
                    @csrf
                    <input type="hidden" name="_dialog" value="cancelar-agendamento">
                    <p>O horário fica livre. O registro continua no histórico.</p>
                    <x-ui.input name="reason" label="Motivo" id="campo-motivo-cancelamento" optional />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-cancelar-agendamento">Cancelar agendamento</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endif
</x-layouts.professional>

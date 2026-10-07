{{--
    HOJE do profissional (Fase 12.5). Responde, nesta ordem: quem esta comigo
    agora, quem e o proximo, o que falta no dia e quanto ja produzi.
    TodayController: so leitura, so do proprio profissional.
--}}
@php
    use App\Modules\Checkout\Enums\AttendanceStatus;
    use App\Modules\Scheduling\Enums\AppointmentSource;
    use App\Modules\Scheduling\Enums\ItemType;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Duration;
    use App\Modules\Shared\Support\Money;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
    $produzido = $earned['commission'] + $earned['tips'];
    $hoje = BusinessTime::today();
@endphp
<x-layouts.professional title="Hoje">
    <header class="pro-head">
        <div class="pro-head__text">
            <p class="eyebrow">Seu dia</p>
            <h1 class="pro-head__title">{{ $greeting }}, {{ strtok($professional->display_name, ' ') }}</h1>
        </div>
        @if ($canBook || $canWalkIn)
            <div class="pro-head__actions">
                @if ($canWalkIn)
                    <x-ui.button :href="route('panel.attendances.create')" variant="secondary" icon="armchair">Encaixe</x-ui.button>
                @endif
                @if ($canBook)
                    <x-ui.button :href="route('panel.appointments.create', ['data' => $hoje])" variant="secondary" icon="calendar-plus">Agendar</x-ui.button>
                @endif
            </div>
        @endif
    </header>

    @include('professional.partials.errors')

    <div class="today">
        {{-- 1. Na cadeira agora --}}
        <section class="today__now" aria-labelledby="agora">
            <h2 id="agora" class="pro-section-title"><span class="live-dot" aria-hidden="true"></span> Na cadeira</h2>
            @forelse ($inChair as $at)
                @php
                    $emAndamento = $at->status === AttendanceStatus::InProgress;
                    $prevista = (int) $at->items->sum('duration_minutes');
                    if ($prevista === 0 && $at->appointment?->starts_at && $at->appointment->ends_at) {
                        $prevista = (int) $at->appointment->starts_at->diffInMinutes($at->appointment->ends_at);
                    }
                    $desde = $at->started_at ?? $at->opened_at;
                    $decorrido = (int) $desde->diffInMinutes($now);
                @endphp
                <article class="chair {{ $emAndamento ? 'chair--live' : 'chair--waiting' }}" data-attendance="{{ $at->id }}" @if ($emAndamento) data-superficie="{{ \App\Modules\SiteContent\Support\Theme::active()->surface('sidebar') }}" @endif>
                    <p class="chair__state">{{ $emAndamento ? 'Atendendo agora' : 'Cliente chegou, aguardando início' }}</p>
                    <h3 class="chair__who">{{ $at->customer_name }}</h3>
                    <p class="chair__what">{{ $at->items->reject(fn ($i) => $i->item_type === ItemType::Product)->pluck('name')->join(', ') ?: 'Atendimento' }}</p>
                    <dl class="chair__times">
                        <div><dt>{{ $emAndamento ? 'Início' : 'Chegada' }}</dt><dd class="figure">{{ BusinessTime::formatLocal($desde, 'H:i') }}</dd></div>
                        <div><dt>Previsto</dt><dd class="figure">{{ $prevista > 0 ? Duration::format($prevista) : '—' }}</dd></div>
                        @if ($emAndamento)
                            <div><dt>Decorrido</dt><dd class="figure"><span x-data="elapsed" data-since="{{ $desde->toIso8601String() }}" x-text="label">{{ $decorrido < 60 ? $decorrido.' min' : intdiv($decorrido, 60).'h'.str_pad((string) ($decorrido % 60), 2, '0', STR_PAD_LEFT) }}</span></dd></div>
                        @endif
                    </dl>
                    @if ($emAndamento && $prevista > 0 && $decorrido > $prevista)
                        <p class="chair__note"><x-ui.badge variant="warning">Passou do previsto</x-ui.badge></p>
                    @endif
                    <div class="chair__actions">
                        @if ($emAndamento)
                            @can('complete', $at)
                                <x-ui.button :href="route('pro.attendances.show', [$at, 'finalizar' => 1])" icon="check" size="lg">Finalizar</x-ui.button>
                            @endcan
                            <x-ui.button :href="route('pro.attendances.show', $at)" variant="secondary" size="lg" icon="receipt">Continuar atendimento</x-ui.button>
                        @else
                            @can('update', $at)
                                <form method="POST" action="{{ route('panel.attendances.start', $at) }}">
                                    @csrf
                                    <x-ui.button type="submit" icon="play" size="lg">Iniciar atendimento</x-ui.button>
                                </form>
                            @endcan
                            <x-ui.button :href="route('pro.attendances.show', $at)" variant="secondary" size="lg">Ver atendimento</x-ui.button>
                        @endif
                    </div>
                </article>
            @empty
                <div class="chair chair--empty">
                    <p class="chair__who">Você não tem atendimentos agora.</p>
                    @if ($next)
                        <p class="chair__what">Próximo às <strong>{{ BusinessTime::formatLocal($next->starts_at, 'H:i') }}</strong>, {{ $next->customer_name }}.</p>
                    @endif
                </div>
            @endforelse
        </section>

        {{-- 2. Proximo cliente --}}
        <section class="today__next" aria-labelledby="proximo">
            <h2 id="proximo" class="pro-section-title">Próximo cliente</h2>
            @if ($next)
                @php
                    $atrasado = $next->starts_at->lt($now);
                    $min = (int) $next->starts_at->diffInMinutes($next->ends_at);
                @endphp
                <article class="next-up" data-next="{{ $next->code }}">
                    <p class="next-up__time figure figure--lg">{{ BusinessTime::formatLocal($next->starts_at, 'H:i') }}</p>
                    <div class="next-up__body">
                        <h3 class="next-up__who">{{ $next->customer_name }}</h3>
                        <p class="next-up__what">{{ $next->items->pluck('name')->join(', ') }} · {{ Duration::format($min) }}</p>
                        <p class="next-up__flags">
                            <x-ui.badge :variant="$next->status->value === 'pending' ? 'warning' : 'neutral'">{{ $next->status->value === 'pending' ? 'A confirmar' : $next->status->label() }}</x-ui.badge>
                            @if ($atrasado)<x-ui.badge variant="danger">Atrasado</x-ui.badge>@endif
                            @if ($next->source === AppointmentSource::WalkIn)<x-ui.badge variant="accent">Encaixe</x-ui.badge>@endif
                        </p>
                        @if ($next->customer_phone)
                            <p><a class="link-arrow" href="tel:{{ preg_replace('/[^0-9+]/', '', $next->customer_phone) }}"><x-icon name="phone" /> {{ $next->customer_phone }}</a></p>
                        @endif
                    </div>
                    <div class="next-up__actions">
                        @if ($canWalkIn && in_array($next->status->value, ['pending', 'confirmed'], true) && $next->attendance === null)
                            <form method="POST" action="{{ route('panel.attendances.open', $next) }}">
                                @csrf
                                <x-ui.button type="submit" icon="play" size="lg" :variant="$inChair->isEmpty() ? 'primary' : 'secondary'">Cliente chegou</x-ui.button>
                            </form>
                        @endif
                        <x-ui.button :href="route('pro.appointments.show', $next)" variant="ghost" size="lg" icon-right="arrow-right">Detalhes</x-ui.button>
                    </div>
                </article>
            @else
                <x-ui.empty-state compact title="Nada mais marcado para hoje" icon="calendar">
                    @if ($canBook)
                        <x-slot:action><x-ui.button :href="route('pro.agenda')" variant="secondary" size="sm">Ver agenda</x-ui.button></x-slot:action>
                    @endif
                </x-ui.empty-state>
            @endif
        </section>

        {{-- Numeros do dia: no celular depois de "na cadeira" e "proximo"; no desktop, no topo. --}}
        <dl class="tally today__tally" aria-label="Seu dia em números">
            <div class="tally__item"><dt>Horários</dt><dd class="figure" data-count="total">{{ $counts['total'] }}</dd></div>
            <div class="tally__item"><dt>Concluídos</dt><dd class="figure" data-count="done">{{ $counts['done'] }}</dd></div>
            <div class="tally__item"><dt>A seguir</dt><dd class="figure" data-count="left">{{ $counts['left'] }}</dd></div>
            <div class="tally__item tally__item--key"><dt>Produzido hoje</dt><dd class="figure" data-earned>{{ $fmt($produzido) }}</dd></div>
        </dl>

        {{-- 3. O resto do dia (a partir de agora), com o tempo livre --}}
        <section class="today__rest" aria-labelledby="resto">
            <div class="pro-section-head">
                <h2 id="resto" class="pro-section-title">Resto do dia</h2>
                <a class="link-arrow text-sm" href="{{ route('pro.agenda') }}">Agenda completa <x-icon name="arrow-right" /></a>
            </div>
            @if ($rest->isEmpty())
                <p class="text-muted">Nenhum outro horário nem tempo livre até o fim do expediente.</p>
            @else
                <ol class="timeline-pro" role="list">
                    @foreach ($rest as $linha)
                        @if ($linha['kind'] === 'appointment')
                            @include('professional.partials.appointment-row', ['a' => $linha['item'], 'now' => $now])
                        @else
                            @include('professional.partials.gap-row', ['kind' => 'free', 'start' => $linha['item']->start, 'end' => $linha['item']->end,
                                'bookUrl' => $canBook ? route('panel.appointments.create', ['data' => $hoje, 'hora' => BusinessTime::formatLocal($linha['item']->start, 'H:i')]) : null])
                        @endif
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- 4. Producao do dia (lancamentos da Fase 7) --}}
        <section class="today__made" aria-labelledby="produzido">
            <h2 id="produzido" class="pro-section-title">Produzido hoje</h2>
            <dl class="summary-list">
                <div><dt>Comissão</dt><dd class="numeric">{{ $fmt($earned['commission']) }}</dd></div>
                <div><dt>Gorjetas</dt><dd class="numeric">{{ $fmt($earned['tips']) }}</dd></div>
                <div><dt>Atendimentos concluídos</dt><dd class="numeric">{{ $completed['count'] }} · {{ $fmt($completed['total']) }}</dd></div>
            </dl>
            <a class="link-arrow text-sm" href="{{ route('pro.earnings') }}">Meus ganhos <x-icon name="arrow-right" /></a>
        </section>
    </div>
</x-layouts.professional>

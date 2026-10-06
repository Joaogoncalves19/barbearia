{{--
    Inicio da conta (redesign): responde, nesta ordem, "quando e o meu proximo
    horario e quanto vou pagar", "preciso fazer alguma coisa", "tenho algum
    beneficio hoje" e o resto da conta. Os valores sao os gravados no
    agendamento (nunca recalculados aqui).
--}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $proximo = $upcoming->first();
    $depois = $upcoming->slice(1);
@endphp
<x-layouts.account title="Minha conta">
    <header class="account-hello">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h1 caps">Olá, {{ strtok($customer->name, ' ') }}</h1>
    </header>

    <section class="stack" aria-labelledby="proximo-horario">
        <h2 id="proximo-horario" class="visually-hidden">{{ $upcoming->count() > 1 ? 'Próximos horários' : 'Próximo horário' }}</h2>

        @if ($proximo === null)
            <x-ui.empty-state title="Nenhum horário marcado" icon="calendar">
                Escolha o serviço, o profissional e o horário em poucos passos.
                <x-slot:action>
                    <x-ui.button :href="route('booking.services')" variant="accent" icon="calendar-plus">Agendar horário</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            {{-- O proximo horario em destaque: quando, o que, com quem, quanto. --}}
            <a class="next-visit" href="{{ route('account.appointments.show', $proximo) }}" data-upcoming="{{ $proximo->code }}">
                <span class="next-visit__label">Seu próximo horário</span>
                <span class="next-visit__when">
                    <span class="next-visit__day caps">{{ \Illuminate\Support\Str::ucfirst(BusinessTime::local($proximo->starts_at)->locale('pt_BR')->translatedFormat('l, d \d\e F')) }}</span>
                    <span class="next-visit__time figure figure--lg">{{ BusinessTime::formatLocal($proximo->starts_at, 'H:i') }}</span>
                </span>
                <span class="next-visit__what">
                    <strong>{{ $proximo->items->pluck('name')->join(', ') ?: 'Atendimento' }}</strong>
                    <span>com {{ $proximo->professional_name ?? 'a definir' }}</span>
                </span>
                <span class="next-visit__foot">
                    @if ($proximo->total_cents !== null)
                        <span><span class="next-visit__k">Valor</span> <span class="figure figure--sm">{{ Money::fromCents((int) $proximo->total_cents)->format() }}</span></span>
                    @endif
                    <span><span class="next-visit__k">Situação</span> {{ $proximo->status->label() }}</span>
                    <span class="next-visit__go">Ver detalhes <x-icon name="arrow-right" /></span>
                </span>
            </a>

            @if ($depois->isNotEmpty())
                <ul class="stack stack-sm" role="list">
                    @foreach ($depois as $a)
                        <li>
                            <a class="account-appt" href="{{ route('account.appointments.show', $a) }}" data-upcoming="{{ $a->code }}">
                                <span class="account-appt__when">
                                    <span class="account-appt__day">{{ BusinessTime::formatLocal($a->starts_at, 'd/m') }}</span>
                                    <span class="account-appt__time numeric">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
                                </span>
                                <span class="account-appt__what">
                                    <strong>{{ $a->items->pluck('name')->join(', ') ?: 'Atendimento' }}</strong>
                                    <span class="text-sm text-muted">com {{ $a->professional_name ?? 'a definir' }}@if ($a->total_cents !== null) · {{ Money::fromCents((int) $a->total_cents)->format() }}@endif</span>
                                </span>
                                <x-icon name="chevron-right" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="cluster">
                <x-ui.button :href="route('booking.services')" variant="accent" size="sm" icon="calendar-plus">Agendar outro horário</x-ui.button>
                <x-ui.button :href="route('account.appointments.index')" variant="secondary" size="sm" icon="history">Todos os agendamentos</x-ui.button>
            </div>
        @endif
    </section>

    {{-- O que pede uma acao do cliente agora. --}}
    @if ($pendingReviews > 0 || $unread > 0)
        <section class="board" aria-labelledby="para-fazer">
            <header class="board__head"><h2 id="para-fazer" class="title">Para você</h2></header>
            <ul class="todo-list" role="list">
                @if ($pendingReviews > 0)
                    <li><a href="{{ route('account.reviews.index') }}"><x-icon name="star" /> <span>{{ $pendingReviews === 1 ? 'Conte como foi o seu último atendimento' : 'Você tem '.$pendingReviews.' atendimentos para avaliar' }}</span> <x-icon name="chevron-right" class="icon-sm" /></a></li>
                @endif
                @if ($unread > 0)
                    <li><a href="{{ route('account.notifications') }}"><x-icon name="bell" /> <span>{{ $unread === 1 ? '1 aviso novo' : $unread.' avisos novos' }}</span> <x-icon name="chevron-right" class="icon-sm" /></a></li>
                @endif
            </ul>
        </section>
    @endif

    {{-- Beneficios de hoje: os mesmos do motor de promocoes (nada vem do navegador). --}}
    @if ($entitlements !== [])
        <section class="perks" aria-labelledby="beneficios-hoje">
            <h2 id="beneficios-hoje" class="eyebrow">Vale para você hoje</h2>
            <ul class="perks__list" role="list">
                @foreach ($entitlements as $e)
                    <li><x-icon name="sparkles" /> <span>{{ $e['label'] }}</span></li>
                @endforeach
            </ul>
            <a class="link-arrow text-sm" href="{{ route('account.loyalty') }}">Ver benefícios e pontos <x-icon name="arrow-right" /></a>
        </section>
    @endif

    <section class="stack" aria-labelledby="resumo-conta">
        <h2 id="resumo-conta" class="eyebrow eyebrow--plain">Sua conta</h2>
        <ul class="account-tiles" role="list">
            <li>
                <a class="account-tile" href="{{ route('account.notifications') }}">
                    <x-icon name="bell" />
                    <span class="account-tile__label">Avisos</span>
                    <span class="account-tile__value">{{ $unread > 0 ? $unread.' '.($unread === 1 ? 'novo' : 'novos') : 'Nada novo' }}</span>
                </a>
            </li>
            <li>
                <a class="account-tile" href="{{ route('account.reviews.index') }}">
                    <x-icon name="star" />
                    <span class="account-tile__label">Avaliações</span>
                    <span class="account-tile__value">{{ $pendingReviews > 0 ? $pendingReviews.' para avaliar' : 'Nenhuma pendente' }}</span>
                </a>
            </li>
            <li>
                <a class="account-tile" href="{{ route('account.loyalty') }}">
                    <x-icon name="sparkles" />
                    <span class="account-tile__label">Benefícios</span>
                    <span class="account-tile__value">{{ count($entitlements) > 0 ? count($entitlements).' valendo hoje' : 'Ver pontos e indicação' }}</span>
                </a>
            </li>
            <li>
                <a class="account-tile" href="{{ route('account.subscription') }}">
                    <x-icon name="badge-check" />
                    <span class="account-tile__label">Assinatura</span>
                    <span class="account-tile__value">{{ $subscription ? $subscription->planName().' · '.$subscription->status->label() : 'Sem assinatura' }}</span>
                </a>
            </li>
            <li>
                <a class="account-tile" href="{{ route('account.receipts.index') }}">
                    <x-icon name="receipt" />
                    <span class="account-tile__label">Comprovantes</span>
                    <span class="account-tile__value">{{ $lastReceipt?->completed_at ? 'Último em '.BusinessTime::formatLocal($lastReceipt->completed_at, 'd/m/Y') : 'Nenhum ainda' }}</span>
                </a>
            </li>
            <li>
                <a class="account-tile" href="{{ route('account.profile.edit') }}">
                    <x-icon name="user" />
                    <span class="account-tile__label">Meus dados</span>
                    <span class="account-tile__value">Nome, celular, e-mail e senha</span>
                </a>
            </li>
        </ul>
    </section>
</x-layouts.account>

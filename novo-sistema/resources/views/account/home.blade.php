@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $proximo = $upcoming->first();
@endphp
<x-layouts.account title="Minha conta">
    <header class="stack stack-sm">
        <p class="eyebrow">Minha conta</p>
        <h1 class="h2">Olá, {{ strtok($customer->name, ' ') }}</h1>
    </header>

    <section class="stack" aria-labelledby="proximo-horario">
        <h2 id="proximo-horario" class="h3">{{ $upcoming->count() > 1 ? 'Próximos horários' : 'Próximo horário' }}</h2>

        @if ($proximo === null)
            <x-ui.empty-state title="Nenhum horário marcado" icon="calendar">
                Escolha o serviço, o profissional e o horário em poucos passos.
                <x-slot:action>
                    <x-ui.button :href="route('booking.services')" variant="accent" icon="calendar-plus">Agendar horário</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="stack" role="list">
                @foreach ($upcoming as $a)
                    <li>
                        <a class="account-appt @if ($loop->first) account-appt--next @endif" href="{{ route('account.appointments.show', $a) }}" data-upcoming="{{ $a->code }}">
                            <span class="account-appt__when">
                                <span class="account-appt__day">{{ BusinessTime::formatLocal($a->starts_at, 'd/m') }}</span>
                                <span class="account-appt__time numeric">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
                            </span>
                            <span class="account-appt__what">
                                <strong>{{ $a->items->pluck('name')->join(', ') ?: 'Atendimento' }}</strong>
                                <span class="text-sm text-muted">com {{ $a->professional_name ?? 'a definir' }} · {{ $a->status->label() }}</span>
                            </span>
                            <x-icon name="chevron-right" />
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="cluster">
                <x-ui.button :href="route('account.appointments.index')" variant="secondary" size="sm" icon="history">Todos os agendamentos</x-ui.button>
                <x-ui.button :href="route('booking.services')" variant="accent" size="sm" icon="calendar-plus">Agendar outro horário</x-ui.button>
            </div>
        @endif
    </section>

    <section class="stack" aria-labelledby="resumo-conta">
        <h2 id="resumo-conta" class="h3">Sua conta</h2>
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

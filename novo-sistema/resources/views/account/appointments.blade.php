@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
@endphp
<x-layouts.account title="Agendamentos">
    <header class="cluster">
        <div class="stack stack-sm">
            <p class="eyebrow">Minha conta</p>
            <h1 class="h2">Agendamentos</h1>
        </div>
        <x-ui.button :href="route('booking.services')" variant="accent" size="sm" icon="calendar-plus">Agendar horário</x-ui.button>
    </header>

    <section class="stack" aria-labelledby="proximos">
        <h2 id="proximos" class="h3">Próximos</h2>
        @if ($upcoming->isEmpty())
            <p class="text-muted">Nenhum horário marcado.</p>
        @else
            <ul class="stack" role="list">
                @foreach ($upcoming as $a)
                    <li>
                        <a class="account-appt" href="{{ route('account.appointments.show', $a) }}" data-upcoming="{{ $a->code }}">
                            <span class="account-appt__when">
                                <span class="account-appt__day">{{ BusinessTime::formatLocal($a->starts_at, 'd/m') }}</span>
                                <span class="account-appt__time numeric">{{ BusinessTime::formatLocal($a->starts_at, 'H:i') }}</span>
                            </span>
                            <span class="account-appt__what">
                                <strong>{{ $a->items->pluck('name')->join(', ') ?: 'Atendimento' }}</strong>
                                <span class="text-sm text-muted">com {{ $a->professional_name ?? 'a definir' }} · {{ $a->status->label() }}@if ($a->total_cents !== null) · {{ Money::fromCents((int) $a->total_cents)->format() }}@endif</span>
                            </span>
                            <x-icon name="chevron-right" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="stack" aria-labelledby="historico">
        <h2 id="historico" class="h3">Histórico</h2>
        @if ($history->isEmpty())
            <p class="text-muted">Seus horários anteriores aparecem aqui.</p>
        @else
            <x-ui.table caption="Histórico de agendamentos" caption-hidden stacked>
                <thead>
                    <tr><th scope="col">Data</th><th scope="col">Serviço</th><th scope="col">Profissional</th><th scope="col">Valor</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($history as $a)
                        <tr data-history="{{ $a->code }}">
                            <td data-label="Data" class="numeric">{{ $a->starts_at ? BusinessTime::formatLocal($a->starts_at, 'd/m/Y H:i') : '—' }}</td>
                            <td data-label="Serviço">{{ $a->items->pluck('name')->join(', ') ?: '—' }}</td>
                            <td data-label="Profissional">{{ $a->professional_name ?? '—' }}</td>
                            <td data-label="Valor" class="numeric">{{ $a->total_cents !== null ? Money::fromCents((int) $a->total_cents)->format() : '—' }}</td>
                            <td data-label="Situação"><x-ui.badge>{{ $a->status->label() }}</x-ui.badge></td>
                            <td><a href="{{ route('account.appointments.show', $a) }}">Detalhes<span class="visually-hidden"> do horário {{ $a->code }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $history->links() }}
        @endif
    </section>
</x-layouts.account>

<x-layouts.account title="Horário {{ $appointment->code }}">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.home') }}">Voltar para minha conta</a>
        <h1 class="h2">Horário {{ $appointment->code }}</h1>
    </header>

    <x-ui.card>
        <dl class="summary-list">
            <div><dt>Data</dt><dd>{{ $appointment->starts_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y \à\s H:i') }}</dd></div>
            <div><dt>Profissional</dt><dd>{{ $appointment->professional_name ?? 'A definir' }}</dd></div>
            <div><dt>Situação</dt><dd>{{ $appointment->status->label() }}</dd></div>
            @foreach ($appointment->items as $item)
                <div><dt>{{ $item->name }}</dt><dd>{{ $item->total_cents !== null ? \App\Modules\Shared\Support\Money::fromCents((int) $item->total_cents)->format() : '—' }}</dd></div>
            @endforeach
        </dl>
    </x-ui.card>

    <p class="text-sm text-muted">Para remarcar ou cancelar, fale com a barbearia. Essa opção chega aqui em breve.</p>
</x-layouts.account>

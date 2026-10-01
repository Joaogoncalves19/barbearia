@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
@endphp
<x-layouts.staff :title="'Caixa de '.BusinessTime::formatLocal($session->opened_at, 'd/m/Y')">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.cash.index') }}">Voltar para o caixa</a>
            <h1 class="page-head__title">Caixa de {{ BusinessTime::formatLocal($session->opened_at, 'd/m/Y') }}</h1>
            <p><x-ui.badge :variant="$session->isOpen() ? 'success' : 'neutral'">{{ $session->status->label() }}</x-ui.badge></p>
        </div>
        @unless ($session->isOpen())
            <x-ui.button :href="route('panel.receipts.cash', $session)" variant="secondary" icon="receipt">Comprovante (imprimir ou e-mail)</x-ui.button>
        @endunless
    </header>

    <x-ui.card title="Resumo">
        <dl class="summary-list">
            <div><dt>Aberto</dt><dd>{{ BusinessTime::formatLocal($session->opened_at) }} · {{ $session->openedBy->name ?? '—' }}</dd></div>
            @if ($session->opening_notes)<div><dt>Observação da abertura</dt><dd>{{ $session->opening_notes }}</dd></div>@endif
            @if ($session->closed_at)
                <div><dt>Fechado</dt><dd>{{ BusinessTime::formatLocal($session->closed_at) }} · {{ $session->closedBy->name ?? '—' }}</dd></div>
                <div><dt>Esperado em dinheiro</dt><dd class="numeric">{{ Money::fromCents((int) $session->expected_cash_cents)->format() }}</dd></div>
                <div><dt>Contado</dt><dd class="numeric">{{ Money::fromCents((int) $session->counted_cash_cents)->format() }}</dd></div>
                <div class="summary-total"><dt>Diferença</dt><dd class="numeric" data-difference>{{ Money::fromCents((int) $session->difference_cents)->format() }}</dd></div>
                @if ($session->closing_notes)<div><dt>Justificativa</dt><dd>{{ $session->closing_notes }}</dd></div>@endif
            @endif
        </dl>
        @include('panel.cash.partials.summary')
    </x-ui.card>

    <x-ui.card title="Movimentações">
        @include('panel.cash.partials.movements')
    </x-ui.card>
</x-layouts.staff>

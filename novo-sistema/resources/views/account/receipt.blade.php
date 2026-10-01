@php
    use App\Modules\Finance\Enums\PaymentKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $a = $attendance;
    $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—';
@endphp
<x-layouts.account title="Comprovante {{ $a->code }}">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('account.home') }}">Voltar para minha conta</a>
        <h1 class="h2">Comprovante {{ $a->code }}</h1>
        <p class="text-muted">Atendimento concluído em {{ $a->completed_at ? BusinessTime::formatLocal($a->completed_at, 'd/m/Y \à\s H:i') : '—' }} com {{ $a->professional_name ?? '—' }}.</p>
    </header>

    <x-ui.card>
        <dl class="summary-list">
            @foreach ($a->items as $item)
                <div><dt>{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ $item->name }}</dt><dd class="numeric">{{ $fmt($item->total_cents) }}</dd></div>
            @endforeach
            @foreach ($a->discounts as $d)
                <div><dt>Desconto</dt><dd class="numeric">−{{ $fmt($d->amount_cents) }}</dd></div>
            @endforeach
            <div class="summary-total"><dt>Total</dt><dd class="numeric">{{ $fmt($a->total_cents) }}</dd></div>
            @foreach ($a->payments as $p)
                <div><dt>{{ $p->kind === PaymentKind::Refund ? 'Estorno' : 'Pago' }} · {{ $p->method->label() }}</dt><dd class="numeric">{{ $p->kind === PaymentKind::Refund ? '−' : '' }}{{ $fmt($p->amount_cents) }}</dd></div>
            @endforeach
        </dl>
    </x-ui.card>
    <p class="text-sm text-muted">Este comprovante não é documento fiscal.</p>
</x-layouts.account>

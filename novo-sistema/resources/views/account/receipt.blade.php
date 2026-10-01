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
                @php $sinal = $p->kind === PaymentKind::Refund ? '−' : ''; @endphp
                @if ($p->amount_cents > 0)
                    <div><dt>{{ $p->kind === PaymentKind::Refund ? 'Estorno' : 'Pago' }} · {{ $p->method->label() }}</dt><dd class="numeric">{{ $sinal }}{{ $fmt($p->amount_cents) }}</dd></div>
                @endif
                @if ((int) $p->tip_cents > 0)
                    <div><dt>{{ $p->kind === PaymentKind::Refund ? 'Estorno de gorjeta' : 'Gorjeta' }} · {{ $p->method->label() }}</dt><dd class="numeric">{{ $sinal }}{{ $fmt($p->tip_cents) }}</dd></div>
                @endif
            @endforeach
        </dl>
    </x-ui.card>
    <div class="cluster">
        <x-ui.button :href="route('account.attendances.print', $a)" variant="secondary" icon="receipt">Imprimir ou enviar por e-mail</x-ui.button>
    </div>
    <p class="text-sm text-muted">Este comprovante não é documento fiscal.</p>
</x-layouts.account>

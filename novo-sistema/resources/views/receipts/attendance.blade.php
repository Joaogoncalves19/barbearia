{{-- Comprovante do atendimento (cliente). Mesmo conteudo na impressao e no e-mail. --}}
@php
    use App\Modules\Finance\Enums\PaymentKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $a = $attendance;
    $fmt = fn (?int $c) => $c !== null ? Money::fromCents($c)->format() : '—';
@endphp
<article class="receipt" aria-label="Comprovante do atendimento {{ $a->code }}">
    @include('receipts._header', ['docTitle' => 'Comprovante de atendimento', 'docMeta' => $a->code.' · '.($a->completed_at ? BusinessTime::formatLocal($a->completed_at, 'd/m/Y H:i') : '—')])

    <p>Cliente: <strong>{{ $a->customer_name ?? '—' }}</strong><br>Profissional: {{ $a->professional_name ?? '—' }}</p>

    <table>
        <thead><tr><th scope="col">Item</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Valor</th></tr></thead>
        <tbody>
            @foreach ($a->items as $item)
                <tr><td>{{ $item->name }}</td><td class="num">{{ $item->quantity }}</td><td class="num">{{ $fmt($item->total_cents) }}</td></tr>
            @endforeach
            <tr><td colspan="2">Subtotal</td><td class="num">{{ $fmt($a->subtotal_cents) }}</td></tr>
            @foreach ($a->discounts as $d)
                <tr><td colspan="2">Desconto · {{ $d->reason ?? $d->kind->label() }}</td><td class="num">−{{ $fmt($d->amount_cents) }}</td></tr>
            @endforeach
            <tr class="receipt__total"><td colspan="2">Total</td><td class="num">{{ $fmt($a->total_cents) }}</td></tr>
        </tbody>
    </table>

    @if ($a->payments->isNotEmpty())
        <table>
            <thead><tr><th scope="col">Pagamento</th><th scope="col" class="num">Valor</th><th scope="col" class="num">Gorjeta</th></tr></thead>
            <tbody>
                @foreach ($a->payments as $p)
                    @php $sinal = $p->kind === PaymentKind::Refund ? '−' : ''; @endphp
                    <tr>
                        <td>{{ $p->kind === PaymentKind::Refund ? 'Estorno' : 'Pago' }} · {{ $p->method->label() }}</td>
                        <td class="num">{{ $sinal }}{{ $fmt($p->amount_cents) }}</td>
                        <td class="num">{{ (int) $p->tip_cents > 0 ? $sinal.$fmt($p->tip_cents) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="receipt__foot">Este comprovante não é documento fiscal.</p>
</article>

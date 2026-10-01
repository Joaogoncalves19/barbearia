{{-- Recibo de repasse ao profissional, com linha para assinatura. --}}
@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $p = $payout;
    $fmt = fn (?int $c) => Money::fromCents((int) $c)->format();
    $forma = $p->method ? ($p->method->value === 'other' ? 'Outro (transferência)' : $p->method->label()) : '—';
@endphp
<article class="receipt" aria-label="Recibo do repasse {{ $p->id }}">
    @include('receipts._header', ['docTitle' => 'Recibo de repasse', 'docMeta' => '#'.$p->id.' · pago em '.($p->paid_on?->format('d/m/Y') ?? '—')])

    <p>Profissional: <strong>{{ $p->professional->display_name ?? '—' }}</strong><br>
        Forma: {{ $forma }}{{ $p->cutoff_at ? ' · valores em aberto até '.BusinessTime::formatLocal($p->cutoff_at) : '' }}</p>

    @if ($p->isReversed())
        <p><strong>Repasse estornado</strong> em {{ BusinessTime::formatLocal($p->reversed_at) }}: {{ $p->reversal_reason }}</p>
    @endif

    @if (! $p->isLegacy())
        <table>
            <thead><tr><th scope="col">Lançamento</th><th scope="col" class="num">Valor</th></tr></thead>
            <tbody>
                @foreach ($commissions as $e)
                    <tr><td>Comissão · {{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m') : '' }} {{ $e->attendance?->code }} {{ $e->item_name ?? $e->reason }}</td><td class="num">{{ $fmt($e->amount_cents) }}</td></tr>
                @endforeach
                @foreach ($tips as $t)
                    <tr><td>Gorjeta · {{ BusinessTime::formatLocal($t->occurred_at, 'd/m') }} {{ $t->attendance?->code }} {{ $t->reason }}</td><td class="num">{{ $fmt($t->amount_cents) }}</td></tr>
                @endforeach
                @foreach ($advances as $v)
                    <tr><td>{{ $v->kind->label() }} · {{ $v->description }}</td><td class="num">−{{ $fmt($v->amount_cents) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table>
        <tbody>
            @if (! $p->isLegacy())
                <tr><td>Comissão</td><td class="num">{{ $fmt($p->commission_cents) }}</td></tr>
            @endif
            <tr><td>Gorjeta</td><td class="num">{{ $fmt($p->tip_cents) }}</td></tr>
            @if (! $p->isLegacy())
                <tr><td>Vales abatidos</td><td class="num">−{{ $fmt($p->advances_cents) }}</td></tr>
            @endif
            <tr class="receipt__total"><td>Líquido pago</td><td class="num">{{ $fmt($p->amount_cents) }}</td></tr>
        </tbody>
    </table>

    <div class="receipt__sign">
        <p>{{ $p->professional->display_name ?? 'Profissional' }}</p>
        <p>{{ $business['name'] }}{{ $p->createdBy ? ' · '.$p->createdBy->name : '' }}</p>
    </div>
    <p class="receipt__foot">Gorjeta é do profissional e não é comissão. Taxas de cartão são custo da barbearia e não foram descontadas.</p>
</article>

{{-- Fechamento de caixa: resumo, por forma e movimentacoes. --}}
@php
    use App\Modules\Finance\Enums\PaymentMethod;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $s = $session;
    $fmt = fn (?int $c) => Money::fromCents((int) $c)->format();
@endphp
<article class="receipt" aria-label="Fechamento de caixa {{ $s->id }}">
    @include('receipts._header', ['docTitle' => 'Fechamento de caixa', 'docMeta' => '#'.$s->id.' · '.($s->closed_at ? 'fechado em '.BusinessTime::formatLocal($s->closed_at) : 'aberto')])

    <p>Aberto em {{ BusinessTime::formatLocal($s->opened_at) }} por {{ $s->openedBy->name ?? '—' }}{{ $s->closed_at ? '. Fechado em '.BusinessTime::formatLocal($s->closed_at).' por '.($s->closedBy->name ?? '—') : '' }}.</p>

    <table>
        <tbody>
            <tr><td>Valor inicial</td><td class="num">{{ $fmt($s->opening_float_cents) }}</td></tr>
            <tr><td>Entradas</td><td class="num">{{ $fmt($summary['inflow']) }}</td></tr>
            <tr><td>Saídas</td><td class="num">−{{ $fmt($summary['outflow']) }}</td></tr>
            <tr class="receipt__total"><td>Esperado em dinheiro</td><td class="num">{{ $fmt($s->expected_cash_cents ?? $summary['expected_cash']) }}</td></tr>
            @if ($s->closed_at)
                <tr><td>Contado</td><td class="num">{{ $fmt($s->counted_cash_cents) }}</td></tr>
                <tr><td>Diferença</td><td class="num">{{ $fmt($s->difference_cents) }}</td></tr>
            @endif
        </tbody>
    </table>
    @if ($s->closing_notes)<p>Justificativa: {{ $s->closing_notes }}</p>@endif

    @if ($summary['by_method'] !== [])
        <table>
            <thead><tr><th scope="col">Por forma (líquido)</th><th scope="col" class="num">Valor</th></tr></thead>
            <tbody>
                @foreach ($summary['by_method'] as $forma => $valor)
                    <tr><td>{{ PaymentMethod::from($forma)->label() }}</td><td class="num">{{ $fmt($valor) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table>
        <thead><tr><th scope="col">Movimentação</th><th scope="col" class="num">Valor</th></tr></thead>
        <tbody>
            @foreach ($movements as $m)
                <tr><td>{{ BusinessTime::formatLocal($m->occurred_at, 'd/m H:i') }} · {{ $m->type->label() }} · {{ $m->method->label() }} · {{ $m->description }}</td><td class="num">{{ $fmt($m->amount_cents) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="receipt__sign">
        <p>{{ $s->closedBy->name ?? 'Responsável' }}</p>
        <p>Conferido por</p>
    </div>
</article>

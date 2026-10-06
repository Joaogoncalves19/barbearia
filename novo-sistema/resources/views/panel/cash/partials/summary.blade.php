{{-- Resumo de um caixa: $session, $summary (CashRegister::summary). --}}
@php
    use App\Modules\Finance\Enums\PaymentMethod;
    use App\Modules\Shared\Support\Money;
@endphp
<div class="stats">
    <div class="stat">
        <span class="stat__label">Valor inicial</span>
        <span class="stat__value numeric">{{ Money::fromCents($session->opening_float_cents)->format() }}</span>
    </div>
    <div class="stat">
        <span class="stat__label">Entradas</span>
        <span class="stat__value numeric">{{ Money::fromCents($summary['inflow'])->format() }}</span>
    </div>
    <div class="stat">
        <span class="stat__label">Saídas</span>
        <span class="stat__value numeric">{{ Money::fromCents($summary['outflow'])->format() }}</span>
    </div>
    {{-- O numero que manda no caixa: o que deve estar na gaveta. --}}
    <div class="stat stat--key">
        <span class="stat__label">Dinheiro esperado na gaveta</span>
        <span class="stat__value numeric" data-expected-cash>{{ Money::fromCents($summary['expected_cash'])->format() }}</span>
        <span class="stat__foot">valor inicial + movimentos em dinheiro</span>
    </div>
</div>
@if ($summary['by_method'] !== [])
    <dl class="summary-list">
        @foreach ($summary['by_method'] as $forma => $valor)
            <div><dt>{{ PaymentMethod::from($forma)->label() }} (líquido)</dt><dd class="numeric">{{ Money::fromCents($valor)->format() }}</dd></div>
        @endforeach
    </dl>
@endif

{{-- Vale-presente para entregar ou enviar. --}}
@php
    use App\Modules\Shared\Support\Money;
    $g = $card;
@endphp
<article class="receipt" aria-label="Vale-presente {{ $g->code }}">
    @include('receipts._header', ['docTitle' => 'Vale-presente', 'docMeta' => 'Emitido em '.($g->issued_at?->format('d/m/Y') ?? '—')])

    @if ($g->recipient_name)<p>Para: <strong>{{ $g->recipient_name }}</strong></p>@endif
    @if ($g->purchaser_name)<p>De: {{ $g->purchaser_name }}</p>@endif
    @if ($g->message)<p><em>{{ $g->message }}</em></p>@endif

    <p class="receipt__code">{{ $g->code }}</p>

    <table>
        <tbody>
            <tr class="receipt__total"><td>Valor</td><td class="num">{{ Money::fromCents($g->amount_cents)->format() }}</td></tr>
            <tr><td>Validade</td><td class="num">{{ $g->expires_on?->format('d/m/Y') ?? 'Sem validade' }}</td></tr>
            <tr><td>Situação</td><td class="num">{{ $g->situationLabel() }}</td></tr>
        </tbody>
    </table>

    <p class="receipt__foot">Apresente o código no pagamento. Uso único: o vale é usado de uma vez, até o valor acima. Não é trocado por dinheiro.</p>
</article>

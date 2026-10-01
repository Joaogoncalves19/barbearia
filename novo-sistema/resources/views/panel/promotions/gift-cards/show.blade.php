@php
    use App\Modules\Loyalty\Enums\GiftCardStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $g = $card;
@endphp
<x-layouts.staff :title="'Vale-presente '.$g->code">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.gift-cards.index') }}">Voltar para vales-presente</a>
            <h1 class="page-head__title">{{ $g->code }}</h1>
            <p><x-ui.badge :variant="$g->isUsable() ? 'success' : 'neutral'">{{ $g->situationLabel() }}</x-ui.badge> <span class="text-muted">{{ Money::fromCents($g->amount_cents)->format() }}</span></p>
        </div>
        <div class="cluster">
            <x-ui.button :href="route('panel.receipts.gift-card', $g)" variant="secondary" icon="receipt">Imprimir ou enviar</x-ui.button>
            @can('gift_cards.cancel')
                @if ($g->status === GiftCardStatus::Available)
                    <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-vale">Cancelar vale</x-ui.button>
                @endif
            @endcan
        </div>
    </header>

    @error('gift_card')@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror

    <x-ui.card title="Dados">
        <dl class="summary-list">
            <div><dt>Valor</dt><dd class="numeric">{{ Money::fromCents($g->amount_cents)->format() }}</dd></div>
            <div><dt>Validade</dt><dd>{{ $g->expires_on?->format('d/m/Y') ?? 'Sem validade' }}</dd></div>
            <div><dt>Emitido</dt><dd>{{ $g->issued_at ? BusinessTime::formatLocal($g->issued_at) : '—' }}{{ $g->soldBy ? ' por '.$g->soldBy->name : '' }}</dd></div>
            <div><dt>Venda</dt><dd>{{ $g->is_legacy ? 'Sistema antigo (venda não registrada)' : ($g->sale_method?->label() ?? '—') }}</dd></div>
            <div><dt>Quem comprou</dt><dd>{{ $g->purchaser_name ?? '—' }}{{ $g->purchaser_email ? ' · '.$g->purchaser_email : '' }}</dd></div>
            <div><dt>Presenteado</dt><dd>{{ $g->recipient_name ?? '—' }}{{ $g->recipient_email ? ' · '.$g->recipient_email : '' }}</dd></div>
            @if ($g->redeemed_at)
                <div><dt>Usado</dt><dd>{{ BusinessTime::formatLocal($g->redeemed_at) }}@if ($g->redeemedAttendance) · <a href="{{ route('panel.attendances.show', $g->redeemedAttendance) }}">{{ $g->redeemedAttendance->code }}</a>@endif</dd></div>
            @endif
            @if ($g->cancelled_at)
                <div><dt>Cancelado</dt><dd>{{ BusinessTime::formatLocal($g->cancelled_at) }}: {{ $g->cancel_reason }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @can('gift_cards.cancel')
        @if ($g->status === GiftCardStatus::Available)
            <x-ui.modal id="cancelar-vale" title="Cancelar este vale-presente?">
                <form method="POST" action="{{ route('panel.gift-cards.cancel', $g) }}" class="stack" id="form-cancelar-vale" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="cancelar-vale">
                    @error('gift_card')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                    <p>O vale deixa de valer.@if (! $g->is_legacy) {{ Money::fromCents($g->amount_cents)->format() }} sai do caixa aberto como devolução, na forma da venda ({{ $g->sale_method?->label() }}).@endif O registro continua no histórico.</p>
                    <x-ui.input name="reason" id="motivo-cancelar-vale" label="Motivo" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-cancelar-vale">Cancelar vale</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endcan
</x-layouts.staff>

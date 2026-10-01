@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
    $p = $payout;
@endphp
<x-layouts.staff :title="'Repasse #'.$p->id">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.commissions.show', $p->professional) }}">Voltar para o extrato</a>
            <h1 class="page-head__title">Repasse #{{ $p->id }} · {{ $p->professional->display_name }}</h1>
            <p>
                @if ($p->isReversed())<x-ui.badge variant="danger">Estornado</x-ui.badge>
                @elseif ($p->isLegacy())<x-ui.badge>Sistema antigo</x-ui.badge>
                @else<x-ui.badge variant="success">Pago</x-ui.badge>@endif
                <span class="text-muted">em {{ $p->paid_on?->format('d/m/Y') ?? '—' }}@if ($p->method) · {{ $p->method->value === 'other' ? 'Outro (transferência)' : $p->method->label() }}@endif @if ($p->createdBy) · por {{ $p->createdBy->name }}@endif</span>
            </p>
        </div>
        <div class="cluster">
            <x-ui.button :href="route('panel.receipts.payout', $p)" variant="secondary" icon="receipt">Recibo (imprimir ou e-mail)</x-ui.button>
            @can('payouts.reverse')
                @if (! $p->isReversed() && ! $p->isLegacy())
                    <x-ui.button variant="danger" icon="undo-2" data-dialog-open="estornar-repasse">Estornar repasse</x-ui.button>
                @endif
            @endcan
        </div>
    </header>

    @error('payout')@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror

    @if ($p->isReversed())
        <x-ui.alert variant="warning">Estornado em {{ BusinessTime::formatLocal($p->reversed_at) }} por {{ $p->reversedBy->name ?? '—' }}: {{ $p->reversal_reason }}. Os valores voltaram ao saldo em aberto do profissional; abaixo, o que este repasse continha.</x-ui.alert>
    @endif

    <div class="stats">
        @if (! $p->isLegacy())
            <div class="stat"><span class="stat__label">Comissão</span><span class="stat__value numeric">{{ $fmt((int) $p->commission_cents) }}</span></div>
        @endif
        <div class="stat"><span class="stat__label">Gorjeta</span><span class="stat__value numeric">{{ $fmt((int) $p->tip_cents) }}</span></div>
        @if (! $p->isLegacy())
            <div class="stat"><span class="stat__label">Vales abatidos</span><span class="stat__value numeric">−{{ $fmt((int) $p->advances_cents) }}</span></div>
        @endif
        <div class="stat"><span class="stat__label">Líquido pago</span><span class="stat__value numeric" data-payout-amount>{{ $fmt($p->amount_cents) }}</span></div>
    </div>

    @if ($p->isLegacy())
        <x-ui.card title="Repasse do sistema antigo">
            <p class="text-sm text-muted">Importado como estava: valor pago{{ $p->reference_month ? ' (referente a '.$p->reference_month.')' : '' }}. O sistema antigo não guardava os lançamentos que compunham o repasse.</p>
        </x-ui.card>
    @else
        <x-ui.card title="O que entrou neste repasse">
            <p class="text-sm text-muted">Lançamentos em aberto até {{ $p->cutoff_at ? BusinessTime::formatLocal($p->cutoff_at) : '—' }}.</p>
            <x-ui.table caption="Lançamentos do repasse" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Atendimento / motivo</th><th scope="col">Valor</th></tr></thead>
                <tbody>
                    @foreach ($commissions as $e)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m H:i') : '—' }}</td>
                            <td data-label="Tipo">Comissão · {{ $e->kind->label() }}</td>
                            <td data-label="Atendimento / motivo">{{ $e->attendance?->code }} {{ $e->item_name ?? $e->reason }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($e->amount_cents) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($tips as $t)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($t->occurred_at, 'd/m H:i') }}</td>
                            <td data-label="Tipo">Gorjeta · {{ $t->kind->label() }}</td>
                            <td data-label="Atendimento / motivo">{{ $t->attendance?->code }} {{ $t->reason }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($t->amount_cents) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($advances as $v)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $v->occurred_at ? BusinessTime::formatLocal($v->occurred_at, 'd/m H:i') : '—' }}</td>
                            <td data-label="Tipo">{{ $v->kind->label() }}</td>
                            <td data-label="Atendimento / motivo">{{ $v->description }}</td>
                            <td data-label="Valor" class="numeric">−{{ $fmt($v->amount_cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            @if ($p->notes)<p class="text-sm">Observação: {{ $p->notes }}</p>@endif
        </x-ui.card>
    @endif

    @can('payouts.reverse')
        @if (! $p->isReversed() && ! $p->isLegacy())
            <x-ui.modal id="estornar-repasse" title="Estornar este repasse?">
                <form method="POST" action="{{ route('panel.payouts.reverse', $p) }}" class="stack" id="form-estornar-repasse" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="estornar-repasse">
                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                    @error('payout')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                    <p>Os lançamentos voltam ao saldo em aberto do profissional (para um novo repasse). O repasse continua no histórico com o motivo.@if ($p->cash_session_id) Foi pago em dinheiro: {{ $fmt($p->amount_cents) }} volta para o caixa aberto.@endif</p>
                    <x-ui.input name="reason" id="motivo-estorno-repasse" label="Motivo" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn btn--danger" form="form-estornar-repasse">Confirmar estorno</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endcan
</x-layouts.staff>

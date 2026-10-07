{{--
    GANHOS do profissional (Fase 12.5). So consulta, so os dados dele:
    saldo e extrato do ProfessionalLedger (Fase 7), regra em vigor do
    CommissionRules. Nenhuma acao administrativa aqui.
--}}
@php
    use App\Modules\Finance\Enums\AdvanceKind;
    use App\Modules\Finance\Enums\LedgerEntryKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
    $kindBadge = fn (LedgerEntryKind $k) => match ($k) { LedgerEntryKind::Earned => 'success', LedgerEntryKind::Refund => 'danger', LedgerEntryKind::Adjustment => 'warning' };
    $situacao = fn ($e) => $e->commission_payout_id !== null ? 'Repassado' : 'Em aberto';
    $maior = max(1, ...array_map(fn ($d) => max(0, $d['commission'] + $d['tips']), $lastDays));
    $comissoes = $statement['commissions'];
    $gorjetas = $statement['tips'];
    $vales = $statement['advances'];
    $mesNome = \Illuminate\Support\Str::ucfirst($monthLabel);
@endphp
<x-layouts.professional title="Meus ganhos">
    <header class="pro-head">
        <div class="pro-head__text">
            <p class="eyebrow">Meus ganhos</p>
            <h1 class="pro-head__title">A receber: <span class="figure" data-open-net>{{ $fmt($open['net']) }}</span></h1>
            <p class="text-muted text-sm">Comissão e gorjeta ainda não repassadas, menos os vales. Valores lançados na conclusão de cada atendimento, com a regra da época.</p>
        </div>
    </header>

    <dl class="tally" aria-label="Saldo em aberto">
        <div class="tally__item"><dt>Comissão</dt><dd class="figure" data-open-commission>{{ $fmt($open['commission']) }}</dd></div>
        <div class="tally__item"><dt>Gorjetas</dt><dd class="figure" data-open-tips>{{ $fmt($open['tips']) }}</dd></div>
        <div class="tally__item"><dt>Vales a abater</dt><dd class="figure" data-open-advances>{{ $fmt($open['advances']) }}</dd></div>
        <div class="tally__item tally__item--key"><dt>Líquido</dt><dd class="figure">{{ $fmt($open['net']) }}</dd></div>
    </dl>

    <div class="pro-split">
        <section class="pro-block" aria-labelledby="periodos">
            <h2 id="periodos" class="pro-section-title">Quanto você ganhou</h2>
            <ul class="items" role="list">
                @foreach ($periods as $rotulo => $v)
                    <li class="items__row">
                        <span class="items__name"><strong>{{ $rotulo }}</strong><span class="items__kind">Comissão {{ $fmt($v['commission']) }} · gorjeta {{ $fmt($v['tips']) }}</span></span>
                        <span class="items__price numeric"><strong>{{ $fmt($v['commission'] + $v['tips']) }}</strong></span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="pro-block" aria-labelledby="sete-dias">
            <h2 id="sete-dias" class="pro-section-title">Últimos 7 dias</h2>
            <ol class="bars" role="list">
                @foreach ($lastDays as $d)
                    @php $v = $d['commission'] + $d['tips']; $nivel = (int) round(max(0, $v) / $maior * 10); @endphp
                    <li class="bars__day @if ($d['today']) is-today @endif">
                        <span class="bars__label">{{ $d['label'] }}</span>
                        <span class="bars__track" aria-hidden="true"><span class="bars__fill bars__fill--{{ $nivel }}"></span></span>
                        <span class="bars__value numeric">{{ $fmt($v) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    <nav class="day-bar" aria-label="Mês do extrato">
        <div class="cluster">
            <x-ui.button :href="route('pro.earnings', ['mes' => $previousMonth])" variant="secondary" size="sm" icon="chevron-left">Mês anterior</x-ui.button>
            <strong class="day-bar__label">{{ $mesNome }}</strong>
            @if ($nextMonth)
                <x-ui.button :href="route('pro.earnings', ['mes' => $nextMonth])" variant="secondary" size="sm" icon-right="chevron-right">Próximo mês</x-ui.button>
            @endif
        </div>
        <p class="text-sm text-muted">Atendimentos concluídos no mês: <strong>{{ $billed['count'] }}</strong> · faturado <strong class="numeric">{{ $fmt($billed['total']) }}</strong></p>
    </nav>

    <section class="pro-block" aria-labelledby="extrato-comissoes">
        <h2 id="extrato-comissoes" class="pro-section-title">Comissões · {{ $mesNome }}</h2>
        @if ($comissoes->isEmpty())
            <p class="text-sm text-muted">Nenhuma comissão neste mês.</p>
        @else
            <x-ui.table caption="Comissões do mês" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Item</th><th scope="col">Regra</th><th scope="col">Comissão</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($comissoes as $e)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m H:i') : '—' }}</td>
                            <td data-label="Item"><x-ui.badge :variant="$kindBadge($e->kind)">{{ $e->kind->label() }}</x-ui.badge> {{ $e->item_name ?? ($e->reason ?? '') }}@if ($e->attendance)<span class="text-sm text-muted"> · {{ $e->attendance->customer_name }}</span>@endif</td>
                            <td data-label="Regra" class="text-sm">{{ $e->rule['descricao'] ?? '—' }}</td>
                            <td data-label="Comissão" class="numeric">{{ $fmt($e->amount_cents) }}</td>
                            <td data-label="Situação">{{ $situacao($e) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="3">Total do mês</th><td class="numeric"><strong>{{ $fmt((int) $comissoes->sum('amount_cents')) }}</strong></td><td></td></tr></tfoot>
            </x-ui.table>
        @endif
    </section>

    <div class="pro-split">
        <section class="pro-block" aria-labelledby="extrato-gorjetas">
            <h2 id="extrato-gorjetas" class="pro-section-title">Gorjetas · {{ $mesNome }}</h2>
            @if ($gorjetas->isEmpty())
                <p class="text-sm text-muted">Nenhuma gorjeta neste mês.</p>
            @else
                <ul class="items" role="list">
                    @foreach ($gorjetas as $t)
                        <li class="items__row">
                            <span class="items__name">{{ $t->attendance?->customer_name ?? $t->reason ?? 'Gorjeta' }}
                                <span class="items__kind">{{ BusinessTime::formatLocal($t->occurred_at, 'd/m H:i') }} · {{ $t->kind->label() }} · {{ $situacao($t) }}</span></span>
                            <span class="items__price numeric">{{ $fmt($t->amount_cents) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-sm">Total do mês: <strong class="numeric">{{ $fmt((int) $gorjetas->sum('amount_cents')) }}</strong></p>
            @endif
            <p class="text-sm text-muted">Gorjeta não é comissão: vai inteira no repasse.</p>
        </section>

        <section class="pro-block" aria-labelledby="extrato-vales">
            <h2 id="extrato-vales" class="pro-section-title">Vales · {{ $mesNome }}</h2>
            @if ($vales->isEmpty())
                <p class="text-sm text-muted">Nenhum vale neste mês.</p>
            @else
                <ul class="items" role="list">
                    @foreach ($vales as $v)
                        <li class="items__row">
                            <span class="items__name">{{ $v->description ?? $v->kind->label() }}
                                <span class="items__kind">{{ $v->occurred_at ? BusinessTime::formatLocal($v->occurred_at, 'd/m') : ($v->issued_on?->format('d/m/Y') ?? '—') }} · {{ $v->kind->label() }} · {{ $v->is_legacy ? 'Histórico' : $situacao($v) }}</span></span>
                            <span class="items__price numeric">{{ $v->kind === AdvanceKind::Advance ? '−' : '' }}{{ $fmt($v->amount_cents) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="pro-split">
        <section class="pro-block" aria-labelledby="repasses">
            <h2 id="repasses" class="pro-section-title">Repasses recebidos</h2>
            @if ($payouts->isEmpty())
                <p class="text-sm text-muted">Nenhum repasse ainda.</p>
            @else
                <ul class="items" role="list">
                    @foreach ($payouts as $p)
                        <li class="items__row">
                            <span class="items__name">{{ $p->paid_on?->format('d/m/Y') ?? '—' }}
                                <span class="items__kind">{{ $p->method?->label() ?? '—' }} · {{ $p->isReversed() ? 'Estornado' : ($p->isLegacy() ? 'Sistema antigo' : 'Pago') }}</span></span>
                            <span class="items__price numeric">{{ $fmt($p->amount_cents) }}</span>
                            @unless ($p->isLegacy())
                                <a class="btn btn--ghost btn--sm" href="{{ route('panel.receipts.payout', $p) }}"><x-icon name="receipt" /><span>Recibo<span class="visually-hidden"> do repasse de {{ $p->paid_on?->format('d/m/Y') }}</span></span></a>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="pro-block" aria-labelledby="regras">
            <h2 id="regras" class="pro-section-title">Sua comissão hoje</h2>
            <dl class="summary-list">
                @foreach ($rules['services'] as $r)
                    <div><dt>{{ $r['name'] }}</dt><dd>{{ $r['rule'] ?? 'Sem regra' }}</dd></div>
                @endforeach
                <div><dt>Produtos</dt><dd>{{ $rules['product'] ?? 'Sem regra' }}</dd></div>
                <div><dt>Atendimento de assinante</dt><dd>{{ $rules['subscription'] ?? 'Sem regra' }}</dd></div>
            </dl>
            <p class="text-sm text-muted">Só consulta: quem define a comissão é o proprietário. Mudar a regra não altera o que já foi lançado.</p>
        </section>
    </div>
</x-layouts.professional>

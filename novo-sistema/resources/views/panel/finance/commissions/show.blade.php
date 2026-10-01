@php
    use App\Modules\Finance\Enums\AdvanceKind;
    use App\Modules\Finance\Enums\LedgerEntryKind;
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;
    $fmt = fn (int $c) => Money::fromCents($c)->format();
    $u = auth('web')->user();
    $kindBadge = fn (LedgerEntryKind $k) => match ($k) { LedgerEntryKind::Earned => 'success', LedgerEntryKind::Refund => 'danger', LedgerEntryKind::Adjustment => 'warning' };
    $nomeMes = ucfirst(\Carbon\CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->locale('pt_BR')->translatedFormat('F \d\e Y'));
    $situacao = function ($e) {
        return $e->commission_payout_id !== null
            ? '<a href="'.e(route('panel.payouts.show', $e->commission_payout_id)).'">Repassado (#'.e($e->commission_payout_id).')</a>'
            : 'Em aberto';
    };
    $linkAtendimento = fn ($at) => $at !== null && $u->can('view', $at)
        ? '<a href="'.e(route('panel.attendances.show', $at)).'">'.e($at->code).'</a>'
        : e($at?->code ?? '—');
@endphp
<x-layouts.staff :title="'Extrato · '.$professional->display_name">
    <header class="page-head">
        <div class="stack stack-sm">
            @can('commissions.view')
                <a class="link-arrow text-sm" href="{{ route('panel.commissions.index') }}">Voltar para comissões</a>
            @endcan
            <h1 class="page-head__title">{{ $professional->display_name }}</h1>
            <p class="text-muted">Extrato de comissão, gorjeta, vales e repasses. Valores calculados na conclusão de cada atendimento, com a regra da época: mudar preço ou regra depois não altera o que já foi lançado.</p>
        </div>
        <div class="cluster">
            @can('payouts.create')
                <x-ui.button :href="route('panel.payouts.create', $professional)" icon="banknote">Repassar</x-ui.button>
            @endcan
            @can('advances.create')
                <x-ui.button variant="secondary" icon="hand-coins" data-dialog-open="vale">Lançar vale</x-ui.button>
            @endcan
            @can('commissions.correct')
                <x-ui.button variant="secondary" icon="pencil" data-dialog-open="ajuste">Ajuste</x-ui.button>
            @endcan
        </div>
    </header>

    @foreach (['ledger', 'advance', 'amount', 'attendance_code'] as $campo)
        @error($campo)@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror
    @endforeach

    <div class="stats">
        <div class="stat"><span class="stat__label">Comissão em aberto</span><span class="stat__value numeric" data-open-commission>{{ $fmt($open['commission']) }}</span></div>
        <div class="stat"><span class="stat__label">Gorjeta em aberto</span><span class="stat__value numeric" data-open-tips>{{ $fmt($open['tips']) }}</span></div>
        <div class="stat"><span class="stat__label">Vales a abater</span><span class="stat__value numeric" data-open-advances>{{ $fmt($open['advances']) }}</span></div>
        <div class="stat"><span class="stat__label">Líquido a receber</span><span class="stat__value numeric" data-open-net>{{ $fmt($open['net']) }}</span><span class="stat__foot">comissão + gorjeta − vales, ainda não repassado</span></div>
    </div>

    <nav class="cluster" aria-label="Mês do extrato">
        <x-ui.button :href="route('panel.commissions.show', [$professional, 'mes' => $previousMonth])" variant="secondary" size="sm" icon="chevron-left">Mês anterior</x-ui.button>
        <strong>{{ $nomeMes }}</strong>
        <x-ui.button :href="route('panel.commissions.show', [$professional, 'mes' => $nextMonth])" variant="secondary" size="sm" icon-right="chevron-right">Próximo mês</x-ui.button>
    </nav>

    <x-ui.card :title="'Comissões · '.$nomeMes">
        @if ($commissions->isEmpty())
            <p class="text-sm text-muted">Nenhuma comissão neste mês.</p>
        @else
            <x-ui.table caption="Comissões do mês" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Atendimento</th><th scope="col">Item</th><th scope="col">Base</th><th scope="col">Regra</th><th scope="col">Comissão</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($commissions as $e)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m H:i') : '—' }}</td>
                            <td data-label="Atendimento">{!! $linkAtendimento($e->attendance) !!}</td>
                            <td data-label="Item"><x-ui.badge :variant="$kindBadge($e->kind)">{{ $e->kind->label() }}</x-ui.badge> {{ $e->item_name ?? ($e->reason ?? '') }}@if ($e->quantity && $e->quantity > 1) × {{ $e->quantity }}@endif
                                @if ($e->kind === LedgerEntryKind::Adjustment)<span class="text-sm text-muted"> · {{ $e->createdBy->name ?? '—' }}</span>@endif</td>
                            <td data-label="Base" class="numeric">{{ $e->kind === LedgerEntryKind::Adjustment ? '—' : $fmt($e->base_cents) }}</td>
                            <td data-label="Regra" class="text-sm">{{ $e->rule['descricao'] ?? '—' }}</td>
                            <td data-label="Comissão" class="numeric">{{ $fmt($e->amount_cents) }}</td>
                            <td data-label="Situação">{!! $situacao($e) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="5">Total do mês</th><td class="numeric"><strong>{{ $fmt((int) $commissions->sum('amount_cents')) }}</strong></td><td></td></tr></tfoot>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="'Gorjetas · '.$nomeMes">
        <p class="text-sm text-muted">Gorjeta é o valor que o cliente deixou para o profissional. Não é comissão e não passa por regra: vai inteira no repasse.</p>
        @if ($tips->isEmpty())
            <p class="text-sm text-muted">Nenhuma gorjeta neste mês.</p>
        @else
            <x-ui.table caption="Gorjetas do mês" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Atendimento</th><th scope="col">Tipo</th><th scope="col">Forma</th><th scope="col">Valor</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($tips as $t)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($t->occurred_at, 'd/m H:i') }}</td>
                            <td data-label="Atendimento">{!! $linkAtendimento($t->attendance) !!}</td>
                            <td data-label="Tipo"><x-ui.badge :variant="$kindBadge($t->kind)">{{ $t->kind->label() }}</x-ui.badge>@if ($t->reason)<span class="text-sm text-muted"> · {{ $t->reason }}</span>@endif</td>
                            <td data-label="Forma">{{ $t->payment?->method->label() ?? '—' }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($t->amount_cents) }}</td>
                            <td data-label="Situação">{!! $situacao($t) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="4">Total do mês</th><td class="numeric"><strong>{{ $fmt((int) $tips->sum('amount_cents')) }}</strong></td><td></td></tr></tfoot>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="'Vales · '.$nomeMes">
        @if ($advances->isEmpty())
            <p class="text-sm text-muted">Nenhum vale neste mês.</p>
        @else
            <x-ui.table caption="Vales do mês" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Motivo</th><th scope="col">Forma</th><th scope="col">Valor</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($advances as $v)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $v->occurred_at ? BusinessTime::formatLocal($v->occurred_at, 'd/m H:i') : ($v->issued_on?->format('d/m/Y') ?? '—') }}</td>
                            <td data-label="Tipo"><x-ui.badge :variant="$v->kind === AdvanceKind::Advance ? 'warning' : 'neutral'">{{ $v->kind->label() }}</x-ui.badge>@if ($v->is_legacy) <span class="text-sm text-muted">(sistema antigo)</span>@endif</td>
                            <td data-label="Motivo">{{ $v->description ?? '—' }}</td>
                            <td data-label="Forma">{{ $v->method?->label() ?? '—' }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($v->amount_cents) }}</td>
                            <td data-label="Situação">{!! $v->is_legacy ? 'Histórico' : $situacao($v) !!}</td>
                            <td>
                                @can('advances.reverse')
                                    @if ($v->kind === AdvanceKind::Advance && ! $v->is_legacy && $v->reversal === null)
                                        <x-ui.button variant="secondary" size="sm" icon="undo-2" data-dialog-open="estorno-vale-{{ $v->id }}">Estornar<span class="visually-hidden"> vale de {{ $fmt($v->amount_cents) }}</span></x-ui.button>
                                        <x-ui.modal :id="'estorno-vale-'.$v->id" title="Estornar vale">
                                            <form method="POST" action="{{ route('panel.advances.reverse', $v) }}" class="stack" id="form-estorno-vale-{{ $v->id }}" novalidate>
                                                @csrf
                                                <input type="hidden" name="_dialog" value="estorno-vale-{{ $v->id }}">
                                                <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                                                @php $deste = old('_dialog') === 'estorno-vale-'.$v->id; @endphp
                                                @if ($deste)@error('advance')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endif
                                                <p>Vale de {{ $fmt($v->amount_cents) }}. O vale continua no histórico; o estorno é um lançamento novo.@if ($v->method?->value === 'cash') O dinheiro volta para o caixa aberto.@endif</p>
                                                <x-ui.input name="reason" :id="'motivo-estorno-vale-'.$v->id" label="Motivo" :value="$deste ? old('reason') : null" :error="$deste ? ($errors->first('reason') ?: false) : false" />
                                            </form>
                                            <x-slot:footer>
                                                <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                                                <button type="submit" class="btn btn--danger" form="form-estorno-vale-{{ $v->id }}">Confirmar estorno</button>
                                            </x-slot:footer>
                                        </x-ui.modal>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Repasses">
        @if ($payouts->isEmpty())
            <p class="text-sm text-muted">Nenhum repasse ainda.</p>
        @else
            <x-ui.table caption="Últimos repasses" caption-hidden stacked>
                <thead><tr><th scope="col">Repasse</th><th scope="col">Pago em</th><th scope="col">Forma</th><th scope="col">Líquido</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                    @foreach ($payouts as $p)
                        <tr>
                            <td data-label="Repasse"><a href="{{ route('panel.payouts.show', $p) }}">#{{ $p->id }}</a></td>
                            <td data-label="Pago em" class="numeric">{{ $p->paid_on?->format('d/m/Y') ?? '—' }}</td>
                            <td data-label="Forma">{{ $p->method?->label() ?? '—' }}</td>
                            <td data-label="Líquido" class="numeric">{{ $fmt($p->amount_cents) }}</td>
                            <td data-label="Situação">@if ($p->isReversed())<x-ui.badge variant="danger">Estornado</x-ui.badge>@elseif ($p->isLegacy())<x-ui.badge>Sistema antigo</x-ui.badge>@else<x-ui.badge variant="success">Pago</x-ui.badge>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    @can('advances.create')
        <x-ui.modal id="vale" title="Lançar vale">
            <form method="POST" action="{{ route('panel.advances.store', $professional) }}" class="stack" id="form-vale" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="vale">
                <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                @php $deste = old('_dialog') === 'vale'; @endphp
                @if ($deste)@error('advance')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endif
                <p>Adiantamento a {{ $professional->display_name }}. É abatido no próximo repasse. Em dinheiro, sai do caixa aberto.</p>
                <x-ui.input name="amount" id="valor-vale" label="Valor" inputmode="decimal" :value="$deste ? old('amount') : null" :error="$deste ? ($errors->first('amount') ?: false) : false" />
                <x-ui.select name="method" id="forma-vale" label="Forma" :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->value === 'other' ? 'Outro (transferência)' : $m->label()])->all()" :value="$deste ? old('method') : 'cash'" />
                <x-ui.input name="description" id="motivo-vale" label="Motivo" :value="$deste ? old('description') : null" :error="$deste ? ($errors->first('description') ?: false) : false" />
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                <button type="submit" class="btn" form="form-vale">Lançar vale</button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    @can('commissions.correct')
        <x-ui.modal id="ajuste" title="Ajuste de comissão ou gorjeta">
            <form method="POST" action="{{ route('panel.commissions.adjust', $professional) }}" class="stack" id="form-ajuste" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="ajuste">
                <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                @php $deste = old('_dialog') === 'ajuste'; @endphp
                @if ($deste)@error('ledger')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endif
                <p>Correção por lançamento novo: nada do que já foi lançado é editado. Fica no histórico com o motivo e o seu nome.</p>
                <x-ui.select name="ledger" id="ajuste-tipo" label="O que corrigir" :options="['commission' => 'Comissão', 'tip' => 'Gorjeta']" :value="$deste ? old('ledger') : 'commission'" />
                <x-ui.select name="direction" id="ajuste-sentido" label="Sentido" :options="['credit' => 'Acrescentar (a favor do profissional)', 'debit' => 'Descontar']" :value="$deste ? old('direction') : 'credit'" />
                <x-ui.input name="amount" id="ajuste-valor" label="Valor" inputmode="decimal" :value="$deste ? old('amount') : null" :error="$deste ? ($errors->first('amount') ?: false) : false" />
                <x-ui.input name="attendance_code" id="ajuste-atendimento" label="Atendimento (código)" hint="Ex.: AT-9MX4RB. Deixe vazio se não for de um atendimento." optional :value="$deste ? old('attendance_code') : null" :error="$deste ? ($errors->first('attendance_code') ?: false) : false" />
                <x-ui.input name="reason" id="ajuste-motivo" label="Motivo" :value="$deste ? old('reason') : null" :error="$deste ? ($errors->first('reason') ?: false) : false" />
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                <button type="submit" class="btn" form="form-ajuste">Registrar ajuste</button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-layouts.staff>

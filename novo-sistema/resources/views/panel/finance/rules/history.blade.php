@php
    use App\Modules\Finance\Models\TipEntry;
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.staff title="Histórico de comissões">
    <header class="page-head">
        <div class="stack stack-sm">
            @can('commissions.view')
                <a class="link-arrow text-sm" href="{{ route('panel.commissions.index') }}">Voltar para comissões</a>
            @endcan
            <h1 class="page-head__title">Histórico</h1>
            <p class="text-muted">Toda mudança de regra, correção manual e estorno de repasse, com quem, quando e por quê. Nada aqui é editado ou apagado.</p>
        </div>
    </header>

    <x-ui.card title="Regras de comissão (todas as versões)">
        @if ($rules->isEmpty())
            <p class="text-sm text-muted">Nenhuma regra.</p>
        @else
            <x-ui.table caption="Versões das regras de comissão" caption-hidden stacked>
                <thead><tr><th scope="col">Escopo</th><th scope="col">Regra</th><th scope="col">Vigência</th><th scope="col">Por</th><th scope="col">Motivo</th></tr></thead>
                <tbody>
                    @foreach ($rules as $r)
                        <tr>
                            <td data-label="Escopo">{{ $r->target->label() }} · {{ $r->scopeLabel() }}</td>
                            <td data-label="Regra">{{ $r->describe() }}</td>
                            <td data-label="Vigência" class="numeric">{{ BusinessTime::formatLocal($r->starts_at, 'd/m/Y H:i') }} — {{ $r->ends_at ? BusinessTime::formatLocal($r->ends_at, 'd/m/Y H:i') : 'em vigor' }}</td>
                            <td data-label="Por">{{ $r->createdBy->name ?? '—' }}</td>
                            <td data-label="Motivo">{{ $r->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Correções manuais">
        @if ($adjustments->isEmpty())
            <p class="text-sm text-muted">Nenhuma correção.</p>
        @else
            <x-ui.table caption="Correções de comissão e gorjeta" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Profissional</th><th scope="col">O quê</th><th scope="col">Valor</th><th scope="col">Atendimento</th><th scope="col">Motivo</th><th scope="col">Por</th></tr></thead>
                <tbody>
                    @foreach ($adjustments as $a)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $a->occurred_at ? BusinessTime::formatLocal($a->occurred_at, 'd/m/Y H:i') : '—' }}</td>
                            <td data-label="Profissional">{{ $a->professional->display_name ?? '—' }}</td>
                            <td data-label="O quê">{{ $a instanceof TipEntry ? 'Gorjeta' : 'Comissão' }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($a->amount_cents) }}</td>
                            <td data-label="Atendimento">{{ $a->attendance?->code ?? '—' }}</td>
                            <td data-label="Motivo">{{ $a->reason }}</td>
                            <td data-label="Por">{{ $a->createdBy->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Repasses estornados">
        @if ($reversals->isEmpty())
            <p class="text-sm text-muted">Nenhum.</p>
        @else
            <x-ui.table caption="Repasses estornados" caption-hidden stacked>
                <thead><tr><th scope="col">Repasse</th><th scope="col">Profissional</th><th scope="col">Valor</th><th scope="col">Estornado em</th><th scope="col">Motivo</th><th scope="col">Por</th></tr></thead>
                <tbody>
                    @foreach ($reversals as $p)
                        <tr>
                            <td data-label="Repasse">@can('view', $p)<a href="{{ route('panel.payouts.show', $p) }}">#{{ $p->id }}</a>@else #{{ $p->id }} @endcan</td>
                            <td data-label="Profissional">{{ $p->professional->display_name ?? '—' }}</td>
                            <td data-label="Valor" class="numeric">{{ $fmt($p->amount_cents) }}</td>
                            <td data-label="Estornado em" class="numeric">{{ BusinessTime::formatLocal($p->reversed_at, 'd/m/Y H:i') }}</td>
                            <td data-label="Motivo">{{ $p->reversal_reason }}</td>
                            <td data-label="Por">{{ $p->reversedBy->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

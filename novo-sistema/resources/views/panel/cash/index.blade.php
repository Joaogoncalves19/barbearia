@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use App\Modules\Shared\Support\Money;
    use Illuminate\Support\Str;
    $u = auth('web')->user();
@endphp
<x-layouts.staff title="Caixa">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Caixa</h1>
            <p class="text-muted">Um caixa aberto por vez na barbearia. Pagamentos dos atendimentos entram no caixa aberto.</p>
        </div>
    </header>

    @error('cash')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($session === null)
        <x-ui.card title="Caixa fechado">
            @can('cash.open')
                <form method="POST" action="{{ route('panel.cash.open') }}" class="stack" novalidate>
                    @csrf
                    <x-ui.input name="opening_float" label="Dinheiro na gaveta (valor inicial)" inputmode="decimal" hint="Conte o troco antes de abrir. Ex.: 100,00 (ou 0)." />
                    <x-ui.input name="opening_notes" label="Observação" optional />
                    <div><x-ui.button type="submit" icon="wallet">Abrir caixa</x-ui.button></div>
                </form>
            @else
                <p>Não há caixa aberto. Peça a quem pode abrir o caixa.</p>
            @endcan
        </x-ui.card>
    @else
        <x-ui.card title="Caixa aberto">
            <p class="text-sm text-muted">Aberto em {{ BusinessTime::formatLocal($session->opened_at) }} por {{ $session->openedBy->name ?? '—' }}.@if ($session->opening_notes) {{ $session->opening_notes }}@endif</p>
            @include('panel.cash.partials.summary')
            <div class="cluster">
                @can('cash.move')
                    <x-ui.button variant="secondary" icon="plus" data-dialog-open="suprimento">Suprimento</x-ui.button>
                    <x-ui.button variant="secondary" icon="banknote" data-dialog-open="sangria">Sangria</x-ui.button>
                @endcan
                @can('cash.close')
                    <x-ui.button icon="wallet" data-dialog-open="fechar-caixa">Fechar caixa</x-ui.button>
                @endcan
            </div>
        </x-ui.card>

        <x-ui.card title="Movimentações">
            @include('panel.cash.partials.movements')
        </x-ui.card>

        @can('cash.move')
            @foreach (['supply' => ['suprimento', 'Suprimento (entrada de dinheiro)', 'Ex.: reforço de troco vindo do cofre.'], 'withdrawal' => ['sangria', 'Sangria (retirada de dinheiro)', 'Ex.: depósito no banco. Nunca mais do que o dinheiro esperado na gaveta.']] as $tipo => [$id, $titulo, $dica])
                <x-ui.modal :id="$id" :title="$titulo">
                    <form method="POST" action="{{ route('panel.cash.move') }}" class="stack" id="form-{{ $id }}" novalidate>
                        @csrf
                        <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                        <input type="hidden" name="type" value="{{ $tipo }}">
                        <input type="hidden" name="_dialog" value="{{ $id }}">
                        @php $deste = old('_dialog') === $id; @endphp
                        <x-ui.input name="amount" :id="'valor-'.$id" label="Valor" inputmode="decimal" :hint="$dica" :value="$deste ? old('amount') : null" :error="$deste ? ($errors->first('amount') ?: false) : false" />
                        <x-ui.input name="reason" :id="'motivo-'.$id" label="Motivo" :value="$deste ? old('reason') : null" :error="$deste ? ($errors->first('reason') ?: false) : false" />
                    </form>
                    <x-slot:footer>
                        <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                        <button type="submit" class="btn" form="form-{{ $id }}">Registrar</button>
                    </x-slot:footer>
                </x-ui.modal>
            @endforeach
        @endcan

        @can('cash.close')
            <x-ui.modal id="fechar-caixa" title="Fechar o caixa?">
                <form method="POST" action="{{ route('panel.cash.close', $session) }}" class="stack" id="form-fechar" novalidate>
                    @csrf
                    <input type="hidden" name="_dialog" value="fechar-caixa">
                    @error('cash')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
                    <p>Esperado em dinheiro: <strong>{{ Money::fromCents($summary['expected_cash'])->format() }}</strong>. Conte a gaveta e informe o valor. Se houver diferença, explique o motivo. Depois de fechado, o caixa não muda mais.</p>
                    <x-ui.input name="counted" id="campo-contado" label="Dinheiro contado" inputmode="decimal" />
                    <x-ui.input name="closing_notes" id="campo-justificativa" label="Justificativa (se houver diferença)" optional />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn" form="form-fechar">Fechar caixa</button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    @endif

    <x-ui.card title="Caixas fechados">
        @if ($history->isEmpty())
            <x-ui.empty-state compact icon="wallet" title="Nenhum caixa fechado ainda." />
        @else
            <x-ui.table caption="Caixas fechados" caption-hidden stacked>
                <thead><tr><th scope="col">Fechado</th><th scope="col">Esperado</th><th scope="col">Contado</th><th scope="col">Diferença</th><th scope="col">Quem fechou</th></tr></thead>
                <tbody>
                    @foreach ($history as $s)
                        <tr>
                            <td data-label="Fechado"><a href="{{ route('panel.cash.show', $s) }}">{{ BusinessTime::formatLocal($s->closed_at) }}</a></td>
                            <td data-label="Esperado" class="numeric">{{ Money::fromCents((int) $s->expected_cash_cents)->format() }}</td>
                            <td data-label="Contado" class="numeric">{{ Money::fromCents((int) $s->counted_cash_cents)->format() }}</td>
                            <td data-label="Diferença" class="numeric">
                                @if ((int) $s->difference_cents === 0) <span class="text-muted">Sem diferença</span>
                                @else <x-ui.badge variant="warning">{{ Money::fromCents((int) $s->difference_cents)->format() }}</x-ui.badge> @endif
                            </td>
                            <td data-label="Quem fechou">{{ $s->closedBy->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

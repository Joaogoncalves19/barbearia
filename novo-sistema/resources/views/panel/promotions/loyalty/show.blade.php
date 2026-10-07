@php
    use App\Modules\Scheduling\Support\BusinessTime;
    use Illuminate\Support\Str;
@endphp
<x-layouts.staff :title="'Pontos · '.$customer->name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.loyalty.customers') }}">Voltar para pontos de clientes</a>
            <h1 class="page-head__title">{{ $customer->name }}</h1>
            <p class="text-muted">Código de indicação: {{ $customer->referral_code ?? '—' }}</p>
        </div>
        @can('loyalty.adjust')
            <x-ui.button variant="secondary" icon="pencil" data-dialog-open="ajuste-pontos">Ajustar pontos</x-ui.button>
        @endcan
    </header>

    @error('loyalty')@if (! old('_dialog'))<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@endif @enderror

    <div class="stats">
        <div class="stat"><span class="stat__label">Saldo</span><span class="stat__value numeric" data-loyalty-balance>{{ $balance }}</span></div>
        <div class="stat"><span class="stat__label">Disponível</span><span class="stat__value numeric" data-loyalty-available>{{ $available }}</span><span class="stat__foot">saldo menos pontos prometidos em resgates</span></div>
    </div>

    @if ($reserved->isNotEmpty())
        <x-ui.card title="Resgates reservados">
            <ul class="stack stack-sm">
                @foreach ($reserved as $r)
                    <li>{{ $r->points }} pontos · {{ $r->reward['descricao'] ?? 'resgate' }}{{ $r->appointment ? ' · agendamento '.$r->appointment->code : '' }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card title="Extrato">
        @if ($entries->isEmpty())
            <x-ui.empty-state compact icon="star" title="Nenhum lançamento." />
        @else
            <x-ui.table caption="Extrato de pontos" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Descrição</th><th scope="col">Pontos</th></tr></thead>
                <tbody>
                    @foreach ($entries as $e)
                        <tr>
                            <td data-label="Quando" class="numeric">{{ $e->occurred_at ? BusinessTime::formatLocal($e->occurred_at, 'd/m/Y H:i') : '—' }}</td>
                            <td data-label="Tipo">{{ $e->kind->label() }}</td>
                            <td data-label="Descrição">{{ $e->description }}{{ $e->createdBy ? ' · '.$e->createdBy->name : '' }}</td>
                            <td data-label="Pontos" class="numeric">{{ $e->points > 0 ? '+' : '' }}{{ $e->points }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    @can('loyalty.adjust')
        <x-ui.modal id="ajuste-pontos" title="Ajustar pontos">
            <form method="POST" action="{{ route('panel.loyalty.adjust', $customer->public_id) }}" class="stack" id="form-ajuste-pontos" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="ajuste-pontos">
                <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                @php $deste = old('_dialog') === 'ajuste-pontos'; @endphp
                @if ($deste)@error('loyalty')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endif
                <p>Lançamento novo, com o seu nome e o motivo. Retirar nunca usa pontos já prometidos num resgate.</p>
                <x-ui.select name="direction" id="ajuste-pontos-sentido" label="Sentido" :options="['credit' => 'Acrescentar', 'debit' => 'Retirar']" :value="$deste ? old('direction') : 'credit'" />
                <x-ui.input name="points" id="ajuste-pontos-valor" label="Pontos" type="number" min="1" inputmode="numeric" :value="$deste ? old('points') : null" :error="$deste ? ($errors->first('points') ?: false) : false" />
                <x-ui.input name="reason" id="ajuste-pontos-motivo" label="Motivo" :value="$deste ? old('reason') : null" :error="$deste ? ($errors->first('reason') ?: false) : false" />
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                <button type="submit" class="btn" form="form-ajuste-pontos">Registrar ajuste</button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-layouts.staff>

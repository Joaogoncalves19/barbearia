@php
    use App\Modules\Finance\Enums\CommissionTarget;
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.staff title="Regras de comissão">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.commissions.index') }}">Voltar para comissões</a>
            <h1 class="page-head__title">Regras de comissão</h1>
            <p class="text-muted">A regra mais específica vence: profissional + serviço, depois serviço, depois profissional, depois o padrão da barbearia. Sem regra, não há comissão. Produtos: profissional, depois padrão. Mudar uma regra vale para atendimentos concluídos daqui em diante; o que já foi calculado não muda.</p>
        </div>
        @can('commissions.history')
            <x-ui.button :href="route('panel.commissions.history')" variant="secondary" icon="history">Histórico</x-ui.button>
        @endcan
    </header>

    @error('rule')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Definir regra">
        <form method="POST" action="{{ route('panel.commission-rules.store') }}" class="stack" novalidate>
            @csrf
            <div class="dashboard-grid">
                <div class="stack">
                    <x-ui.select name="target" label="Sobre" :options="['service' => 'Serviços', 'product' => 'Produtos vendidos']" value="service" />
                    <x-ui.select name="professional_id" label="Profissional" :options="$professionals->pluck('display_name', 'id')->all()" placeholder="Todos (padrão)" optional />
                    <x-ui.select name="service_id" label="Serviço" :options="$services->pluck('name', 'id')->all()" placeholder="Todos os serviços" optional hint="Só para regras sobre serviços." />
                </div>
                <div class="stack">
                    <x-ui.select name="type" label="Tipo" :options="['percent' => 'Percentual', 'fixed' => 'Valor fixo por serviço', 'none' => 'Sem comissão']" value="percent" />
                    <x-ui.input name="value" label="Percentual ou valor" inputmode="decimal" hint="Ex.: 40 (para 40%) ou 15,00 (valor fixo). Vazio para “sem comissão”." optional />
                    <x-ui.input name="reason" label="Motivo da mudança" optional />
                </div>
            </div>
            <div><x-ui.button type="submit" icon="percent">Salvar regra</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Regras em vigor">
        @if ($current->isEmpty())
            <x-ui.empty-state title="Nenhuma regra" icon="percent">Sem regras, nenhum atendimento gera comissão (a gorjeta continua sendo do profissional).</x-ui.empty-state>
        @else
            <x-ui.table caption="Regras de comissão em vigor" caption-hidden stacked>
                <thead><tr><th scope="col">Sobre</th><th scope="col">Escopo</th><th scope="col">Regra</th><th scope="col">Desde</th><th scope="col">Por</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($current as $r)
                        <tr>
                            <td data-label="Sobre">{{ $r->target->label() }}</td>
                            <td data-label="Escopo">{{ $r->scopeLabel() }}</td>
                            <td data-label="Regra"><strong>{{ $r->describe() }}</strong>@if ($r->reason)<span class="text-sm text-muted"> · {{ $r->reason }}</span>@endif</td>
                            <td data-label="Desde" class="numeric">{{ BusinessTime::formatLocal($r->starts_at, 'd/m/Y H:i') }}</td>
                            <td data-label="Por">{{ $r->createdBy->name ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('panel.commission-rules.clear') }}">
                                    @csrf
                                    <input type="hidden" name="rule_id" value="{{ $r->id }}">
                                    <x-ui.button type="submit" variant="secondary" size="sm" icon="x">Encerrar<span class="visually-hidden"> regra {{ $r->scopeLabel() }}</span></x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

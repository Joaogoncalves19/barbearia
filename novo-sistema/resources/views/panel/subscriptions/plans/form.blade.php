@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $atual = $plan?->currentVersion;
    $marcados = array_map('intval', (array) old('services', $atual?->serviceIds() ?? []));
    $preco = old('price', $atual !== null ? number_format($atual->price_cents / 100, 2, ',', '.') : '');
@endphp
<x-layouts.staff :title="$plan ? 'Plano '.$plan->name : 'Novo plano'">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.plans.index') }}">Voltar para planos</a>
            <h1 class="page-head__title">{{ $plan ? $plan->name : 'Novo plano' }}</h1>
            @if ($plan)
                <p><x-ui.badge :variant="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'Aberto para adesões' : 'Fechado para adesões' }}</x-ui.badge>
                    <span class="text-muted">{{ $atual?->priceLabel() }} · versão {{ $atual?->version }}</span></p>
            @endif
        </div>
        @if ($plan)
            <form method="POST" action="{{ route('panel.plans.status', $plan) }}">
                @csrf
                <input type="hidden" name="active" value="{{ $plan->is_active ? '0' : '1' }}">
                <x-ui.button type="submit" variant="secondary" :icon="$plan->is_active ? 'circle-x' : 'circle-check'">{{ $plan->is_active ? 'Fechar para novas adesões' : 'Abrir para novas adesões' }}</x-ui.button>
            </form>
        @endif
    </header>

    @error('plan')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <form method="POST" action="{{ $plan ? route('panel.plans.versions.store', $plan) : route('panel.plans.store') }}" class="stack" novalidate>
        @csrf
        <x-ui.card :title="$plan ? 'Nova versão (preço ou serviços)' : 'Dados do plano'">
            <div class="stack">
                @unless ($plan)
                    <x-ui.input name="name" label="Nome" hint="Ex.: Clube do Corte." />
                    <x-ui.input name="description" label="Descrição" optional />
                @endunless
                <x-ui.input name="price" label="Preço mensal" inputmode="decimal" :value="$preco" hint="Ex.: 99,90. Cobrado todo mês pelo Stripe." />
                <fieldset class="check-group stack stack-sm">
                    <legend>Serviços incluídos (saem de graça, sem limite)</legend>
                    @error('services')<p class="field__error" role="alert"><x-icon name="circle-x" class="icon-sm" /> {{ $message }}</p>@enderror
                    @foreach ($services as $id => $nome)
                        <label class="choice" for="servico-{{ $id }}">
                            <input type="checkbox" id="servico-{{ $id }}" name="services[]" value="{{ $id }}" @checked(in_array((int) $id, $marcados, true))>
                            <span class="choice__text"><span>{{ $nome }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
                @if ($plan)
                    <x-ui.input name="reason" label="Motivo da mudança" hint="Fica no histórico do plano." />
                @endif
            </div>
        </x-ui.card>
        <div><x-ui.button type="submit">{{ $plan ? 'Criar nova versão' : 'Criar plano' }}</x-ui.button></div>
    </form>

    @if ($plan)
        <x-ui.card title="Versões">
            <x-ui.table caption="Versões do plano" caption-hidden stacked>
                <thead><tr><th scope="col">Versão</th><th scope="col">Preço</th><th scope="col">Serviços</th><th scope="col">Vigência</th><th scope="col">Motivo</th></tr></thead>
                <tbody>
                    @foreach ($plan->versions->sortByDesc('version') as $v)
                        <tr>
                            <td data-label="Versão" class="numeric">{{ $v->version }}@if ($v->current_plan_id) <x-ui.badge variant="success">atual</x-ui.badge>@endif</td>
                            <td data-label="Preço" class="numeric">{{ $v->priceLabel() }}</td>
                            <td data-label="Serviços">{{ $v->services->pluck('name')->join(', ') ?: '—' }}</td>
                            <td data-label="Vigência">{{ BusinessTime::formatLocal($v->starts_at, 'd/m/Y') }} – {{ $v->ends_at ? BusinessTime::formatLocal($v->ends_at, 'd/m/Y') : 'hoje' }}</td>
                            <td data-label="Motivo">{{ $v->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.staff>

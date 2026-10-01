@php use App\Modules\Shared\Support\Money; @endphp
<x-layouts.staff title="Cupons">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Cupons</h1>
            <p class="text-muted">Desconto em serviços (nunca em produtos). Um uso por cliente; vale um desconto só por atendimento, o maior. O uso é reservado ao agendar e conta quando o atendimento é concluído; cancelamento devolve.</p>
        </div>
        @can('coupons.manage')
            <x-ui.button :href="route('panel.coupons.create')" icon="plus">Novo cupom</x-ui.button>
        @endcan
    </header>

    <nav class="cluster" aria-label="Filtro de cupons">
        @foreach (['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'] as $chave => $rotulo)
            <x-ui.button :href="route('panel.coupons.index', ['situacao' => $chave])" size="sm" :variant="$filter === $chave ? 'primary' : 'secondary'" :aria-current="$filter === $chave ? 'page' : null">{{ $rotulo }}</x-ui.button>
        @endforeach
    </nav>

    <x-ui.card title="Cupons">
        @if ($coupons->isEmpty())
            <x-ui.empty-state title="Nenhum cupom" icon="tag">Crie um cupom para divulgar.</x-ui.empty-state>
        @else
            <x-ui.table caption="Cupons" caption-hidden stacked>
                <thead><tr><th scope="col">Código</th><th scope="col">Desconto</th><th scope="col">Usos</th><th scope="col">Validade</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($coupons as $c)
                        <tr>
                            <td data-label="Código"><strong>{{ $c->code }}</strong>@if ($c->description)<span class="text-sm text-muted"> · {{ $c->description }}</span>@endif</td>
                            <td data-label="Desconto">{{ $c->describe() }}</td>
                            <td data-label="Usos" class="numeric">{{ $c->uses_count }}{{ $c->max_uses ? ' de '.$c->max_uses : '' }}<span class="text-sm text-muted"> ({{ $c->reserved_count }} reservado{{ $c->reserved_count === 1 ? '' : 's' }})</span></td>
                            <td data-label="Validade" class="numeric">{{ $c->expires_on?->format('d/m/Y') ?? 'Sem validade' }}</td>
                            <td data-label="Situação"><x-ui.badge :variant="$c->is_active ? 'success' : 'neutral'">{{ $c->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge></td>
                            <td>
                                @can('coupons.manage')
                                    <div class="cluster">
                                        <x-ui.button :href="route('panel.coupons.edit', $c)" variant="secondary" size="sm" icon="pencil">Editar<span class="visually-hidden"> {{ $c->code }}</span></x-ui.button>
                                        <form method="POST" action="{{ route('panel.coupons.status', $c) }}">
                                            @csrf
                                            <input type="hidden" name="active" value="{{ $c->is_active ? 0 : 1 }}">
                                            <x-ui.button type="submit" variant="secondary" size="sm" icon="power">{{ $c->is_active ? 'Desativar' : 'Ativar' }}<span class="visually-hidden"> {{ $c->code }}</span></x-ui.button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

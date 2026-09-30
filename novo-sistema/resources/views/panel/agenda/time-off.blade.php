<x-layouts.staff title="Folgas">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Folgas</h1>
            <p class="text-muted">Dias inteiros sem atendimento (férias, atestado, folga, treinamento). Agendamentos já feitos não são alterados.</p>
        </div>
    </header>

    <x-ui.card title="Nova folga">
        <form method="POST" action="{{ route('panel.time-off.store') }}" class="stack" novalidate>
            @csrf
            <div class="form-grid">
                <x-ui.select name="professional_id" label="Profissional" :options="$professionals->pluck('display_name', 'id')->all()" placeholder="Escolha" />
                <x-ui.select name="kind" label="Tipo" :options="$kinds" />
                <x-ui.input name="starts_on" label="De" type="date" />
                <x-ui.input name="ends_on" label="Até (inclusive)" type="date" />
                <x-ui.input name="reason" label="Motivo" optional />
            </div>
            <div><x-ui.button type="submit" icon="plus">Registrar folga</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Próximas e em andamento">
        @if ($items->isEmpty())
            <p class="text-sm text-muted">Nenhuma folga futura.</p>
        @else
            <x-ui.table caption="Folgas futuras" caption-hidden stacked>
                <thead><tr><th scope="col">Profissional</th><th scope="col">Período</th><th scope="col">Tipo</th><th scope="col">Motivo</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($items as $t)
                        <tr>
                            <td data-label="Profissional">{{ $t->professional?->display_name }}</td>
                            <td data-label="Período">{{ $t->starts_on->format('d/m/Y') }} a {{ $t->ends_on->format('d/m/Y') }}</td>
                            <td data-label="Tipo">{{ $t->kind->label() }}</td>
                            <td data-label="Motivo">{{ $t->reason ?? '—' }}</td>
                            <td>
                                <x-ui.button variant="ghost" size="sm" icon="trash-2" data-dialog-open="remover-folga-{{ $t->id }}">Remover<span class="visually-hidden"> folga de {{ $t->professional?->display_name }}</span></x-ui.button>
                                <x-ui.confirm :id="'remover-folga-'.$t->id" title="Remover esta folga?" :action="route('panel.time-off.destroy', $t)" method="DELETE" confirm-label="Remover">
                                    <p>{{ $t->professional?->display_name }} volta a ficar disponível de {{ $t->starts_on->format('d/m') }} a {{ $t->ends_on->format('d/m') }}.</p>
                                </x-ui.confirm>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    @if ($past->isNotEmpty())
        <x-ui.card title="Anteriores (histórico)">
            <ul class="stack stack-sm text-sm">
                @foreach ($past as $t)
                    <li>{{ $t->professional?->display_name }}: {{ $t->starts_on->format('d/m/Y') }} a {{ $t->ends_on->format('d/m/Y') }} ({{ $t->kind->label() }})</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</x-layouts.staff>

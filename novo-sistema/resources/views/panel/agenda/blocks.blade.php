@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff title="Bloqueios">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Bloqueios de agenda</h1>
            <p class="text-muted">Horários que ninguém pode reservar: de um profissional (reunião, consulta) ou da barbearia inteira (feriado, evento). Agendamentos já feitos não são alterados.</p>
        </div>
    </header>

    <x-ui.card title="Novo bloqueio">
        <form method="POST" action="{{ route('panel.blocks.store') }}" class="stack" novalidate>
            @csrf
            <div class="form-grid">
                <x-ui.select name="professional_id" label="De quem" :options="['' => 'Barbearia inteira'] + $professionals->pluck('display_name', 'id')->all()" optional />
                <x-ui.input name="date" label="Data" type="date" :value="$today" />
                <x-ui.input name="end_date" label="Até a data" type="date" hint="Deixe vazio para um dia só." optional />
                <x-ui.input name="start" label="Início" type="time" optional />
                <x-ui.input name="end" label="Fim" type="time" optional />
                <x-ui.input name="reason" label="Motivo" />
            </div>
            <x-ui.checkbox name="all_day" label="Dia inteiro (ignora início e fim)" />
            <div><x-ui.button type="submit" icon="plus">Criar bloqueio</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Próximos e em andamento">
        @if ($items->isEmpty())
            <p class="text-sm text-muted">Nenhum bloqueio futuro.</p>
        @else
            <x-ui.table caption="Bloqueios futuros" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">De quem</th><th scope="col">Motivo</th><th scope="col">Criado por</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($items as $b)
                        <tr>
                            <td data-label="Quando">{{ BusinessTime::formatLocal($b->starts_at) }} – {{ BusinessTime::formatLocal($b->ends_at) }}</td>
                            <td data-label="De quem">{{ $b->professional?->display_name ?? 'Barbearia inteira' }}</td>
                            <td data-label="Motivo">{{ $b->reason }}</td>
                            <td data-label="Criado por">{{ $b->createdBy?->name ?? '—' }}</td>
                            <td>
                                <x-ui.button variant="ghost" size="sm" icon="trash-2" data-dialog-open="remover-bloqueio-{{ $b->id }}">Remover<span class="visually-hidden"> bloqueio {{ $b->reason }}</span></x-ui.button>
                                <x-ui.confirm :id="'remover-bloqueio-'.$b->id" title="Remover este bloqueio?" :action="route('panel.blocks.destroy', $b)" method="DELETE" confirm-label="Remover">
                                    <p>O período volta a aceitar agendamentos.</p>
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
                @foreach ($past as $b)
                    <li>{{ BusinessTime::formatLocal($b->starts_at) }} – {{ BusinessTime::formatLocal($b->ends_at) }}: {{ $b->professional?->display_name ?? 'Barbearia' }} ({{ $b->reason }})</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</x-layouts.staff>

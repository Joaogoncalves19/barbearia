<x-layouts.staff :title="'Expediente de '.$professional->display_name">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.professionals.show', $professional) }}">Voltar para a ficha</a>
            <h1 class="page-head__title">Expediente de {{ $professional->display_name }}</h1>
            <p class="text-muted">A disponibilidade é a interseção com o horário da barbearia. Mudanças valem para agendamentos novos.</p>
        </div>
    </header>

    <form method="POST" action="{{ route('panel.schedule.working-hours.update', $professional) }}" class="stack" novalidate>
        @csrf
        @method('PUT')
        <x-ui.card title="Dias e horários">
            <fieldset class="check-group">
                <legend>Como trabalha</legend>
                <div class="stack stack-sm">
                    <x-ui.radio name="mode" value="shop" label="Segue o horário da barbearia" :checked="$followsShop" />
                    <x-ui.radio name="mode" value="own" label="Tem horário próprio (abaixo)" :checked="! $followsShop" />
                </div>
            </fieldset>

            <x-ui.table caption="Horário próprio" caption-hidden stacked>
                <thead><tr><th scope="col">Dia</th><th scope="col">Trabalha</th><th scope="col">Início</th><th scope="col">Fim</th></tr></thead>
                <tbody>
                    @foreach ($weekdays as $d => $nome)
                        @php $h = $hours->get($d); @endphp
                        <tr>
                            <th scope="row">{{ $nome }}</th>
                            <td data-label="Trabalha">
                                <input type="hidden" name="days[{{ $d }}][works]" value="0">
                                <x-ui.checkbox name="days[{{ $d }}][works]" :id="'d-'.$d.'-trabalha'" :label="'Trabalha '.mb_strtolower($nome)" :checked="$h !== null || $followsShop" />
                            </td>
                            <td data-label="Início"><x-ui.input name="days[{{ $d }}][start]" :id="'d-'.$d.'-inicio'" :label="$nome.': início'" type="time" :value="$h ? substr($h->starts_at, 0, 5) : '09:00'" optional /></td>
                            <td data-label="Fim"><x-ui.input name="days[{{ $d }}][end]" :id="'d-'.$d.'-fim'" :label="$nome.': fim'" type="time" :value="$h ? substr($h->ends_at, 0, 5) : '19:00'" optional />
                                @error("days.$d.start")<p class="field__error">{{ $message }}</p>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <p class="text-sm text-muted">Folga fixa (ex.: todo domingo) = desmarque o dia. Almoço e outras pausas ficam abaixo.</p>
        </x-ui.card>
        <div><x-ui.button type="submit">Salvar expediente</x-ui.button></div>
    </form>

    <x-ui.card title="Pausas">
        @if ($breaks->isEmpty())
            <p class="text-sm text-muted">Nenhuma pausa cadastrada.</p>
        @else
            <ul class="stack stack-sm">
                @foreach ($breaks as $b)
                    <li class="cluster">
                        <span>{{ $b->weekday === null ? 'Todos os dias' : $weekdays[$b->weekday] }}, {{ substr($b->starts_at, 0, 5) }}–{{ substr($b->ends_at, 0, 5) }}{{ $b->label ? ' ('.$b->label.')' : '' }}</span>
                        <form method="POST" action="{{ route('panel.schedule.breaks.destroy', [$professional, $b]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash-2">Remover<span class="visually-hidden"> pausa {{ substr($b->starts_at, 0, 5) }}</span></x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('panel.schedule.breaks.store', $professional) }}" class="stack stack-sm" novalidate>
            @csrf
            <div class="form-grid">
                <x-ui.select name="weekday" label="Dia" :options="['' => 'Todos os dias'] + $weekdays" optional />
                <x-ui.input name="start" label="Início da pausa" type="time" />
                <x-ui.input name="end" label="Fim da pausa" type="time" />
                <x-ui.input name="label" label="Descrição" optional />
            </div>
            <div><x-ui.button type="submit" variant="secondary" icon="plus">Adicionar pausa</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.staff>

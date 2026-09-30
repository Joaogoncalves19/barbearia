<x-layouts.staff title="Funcionamento e regras">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Funcionamento e regras da agenda</h1>
            <p class="text-muted">Horários no fuso da barbearia ({{ $zone }}). Dia sem horário = fechado. Mudanças valem para agendamentos novos.</p>
        </div>
    </header>

    <form method="POST" action="{{ route('panel.schedule.settings.update') }}" class="stack" novalidate>
        @csrf
        @method('PUT')
        @error('hours')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

        <x-ui.card title="Horário de funcionamento">
            <p class="text-sm text-muted">Até dois períodos por dia (ex.: 09:00–12:00 e 13:00–19:00 quando fecha para almoço).</p>
            <x-ui.table caption="Horário de funcionamento" caption-hidden stacked>
                <thead><tr><th scope="col">Dia</th><th scope="col">1º período</th><th scope="col">2º período (opcional)</th></tr></thead>
                <tbody>
                    @foreach ($weekdays as $d => $nome)
                        @php $dia = $hours->get($d, collect())->values(); @endphp
                        <tr>
                            <th scope="row">{{ $nome }}</th>
                            @for ($i = 0; $i < 2; $i++)
                                <td data-label="{{ $i === 0 ? '1º período' : '2º período' }}">
                                    <div class="cluster">
                                        <x-ui.input name="hours[{{ $d }}][{{ $i }}][start]" :id="'h-'.$d.'-'.$i.'-inicio'" :label="$nome.': abre'" type="time" :value="isset($dia[$i]) ? substr($dia[$i]->starts_at, 0, 5) : null" optional />
                                        <x-ui.input name="hours[{{ $d }}][{{ $i }}][end]" :id="'h-'.$d.'-'.$i.'-fim'" :label="$nome.': fecha'" type="time" :value="isset($dia[$i]) ? substr($dia[$i]->ends_at, 0, 5) : null" optional />
                                    </div>
                                    @error("hours.$d.$i.start")<p class="field__error">{{ $message }}</p>@enderror
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Regras de agendamento">
            <div class="form-grid">
                @foreach ($fields as $campo => $def)
                    @if (is_bool($def['default']))
                        <input type="hidden" name="policy[{{ $campo }}]" value="0">
                        <x-ui.checkbox name="policy[{{ $campo }}]" :id="'p-'.$campo" :label="$def['label']" :checked="(bool) $policy[$campo]" />
                    @else
                        <x-ui.input name="policy[{{ $campo }}]" :id="'p-'.$campo" :label="$def['label']" type="number" inputmode="numeric"
                            :value="$policy[$campo]" :error="$errors->first('policy.'.$campo) ?: null" :hint="'De '.$def['min'].' a '.$def['max'].'.'" />
                    @endif
                @endforeach
            </div>
        </x-ui.card>

        <div><x-ui.button type="submit">Salvar</x-ui.button></div>
    </form>
</x-layouts.staff>

{{--
    Ativar/desativar. Desativar tira de novos agendamentos: pede confirmacao
    (modal) e explica que o historico nao muda. Ativar e direto.
    Parametros: $route, $model, $active, $label (nome do item), $id (unico na
    pagina), $effect (o que acontece ao desativar).
--}}
@if ($active)
    <x-ui.button variant="ghost" size="sm" icon="power" data-dialog-open="{{ $id }}">Desativar<span class="visually-hidden"> {{ $label }}</span></x-ui.button>
    <x-ui.confirm :id="$id" :title="'Desativar '.$label.'?'" :action="route($route, $model)" :fields="['active' => 0]" confirm-label="Desativar">
        <p>{{ $effect }}</p>
        <p class="text-sm text-muted">O histórico (agendamentos, valores, relatórios) não muda. Você pode ativar de novo quando quiser.</p>
    </x-ui.confirm>
@else
    <form method="POST" action="{{ route($route, $model) }}">
        @csrf
        <input type="hidden" name="active" value="1">
        <x-ui.button type="submit" variant="ghost" size="sm" icon="power">Ativar<span class="visually-hidden"> {{ $label }}</span></x-ui.button>
    </form>
@endif

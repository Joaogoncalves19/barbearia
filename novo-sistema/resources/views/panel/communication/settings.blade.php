<x-layouts.staff title="Lembretes e avisos">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Lembretes e avisos</h1>
            <p class="text-muted">Lembretes do horário (véspera e algumas horas antes, com link para confirmar presença), pedido de avaliação depois do atendimento e ritmo de envio das campanhas. O provedor de e-mail é configurado no servidor, não aqui.</p>
        </div>
    </header>

    @error('settings')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card>
        <form method="POST" action="{{ route('panel.communication.settings.update') }}" class="stack" novalidate>
            @csrf
            @method('PUT')
            @foreach ($fields as $campo => $def)
                @if (is_bool($def['default']))
                    <x-ui.switch :name="$campo" :label="$def['label']" :checked="(bool) $settings[$campo]" />
                @else
                    <x-ui.input :name="$campo" type="number" :label="$def['label']" :value="$settings[$campo]" :min="$def['min'] ?? 0" :max="$def['max'] ?? null" :hint="'Entre '.($def['min'] ?? 0).' e '.($def['max'] ?? '').'.'" />
                @endif
            @endforeach
            <div><x-ui.button type="submit" variant="accent">Salvar</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.staff>

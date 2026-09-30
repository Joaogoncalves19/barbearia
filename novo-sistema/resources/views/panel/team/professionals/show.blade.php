<x-layouts.staff :title="$professional->display_name">
    <header class="page-head">
        <div class="cluster">
            <x-ui.avatar :name="$professional->display_name" :src="$professional->photoUrl()" size="lg" />
            <div class="stack stack-sm">
                <p class="text-muted">Ficha do profissional</p>
                <h1 class="page-head__title">{{ $professional->display_name }}</h1>
                @if ($professional->headline)<p class="text-muted">{{ $professional->headline }}</p>@endif
            </div>
        </div>
        @can('professionals.update')
            <x-ui.button :href="route('panel.professionals.edit', $professional)" variant="secondary" icon="pencil">Editar</x-ui.button>
        @endcan
    </header>

    <div class="dashboard-grid">
        <x-ui.card title="Situação">
            <dl class="summary-list">
                <div><dt>Na equipe</dt><dd>{{ $professional->is_active ? 'Ativo' : 'Inativo' }}</dd></div>
                <div><dt>Recebe agendamentos</dt><dd>{{ $professional->isBookable() ? 'Sim' : 'Não' }}</dd></div>
                <div><dt>No site</dt><dd>{{ $professional->is_active && $professional->is_public ? 'Sim' : 'Não' }}</dd></div>
                <div><dt>Acesso ao sistema</dt><dd>{{ $professional->user?->loginLabel() ?? 'Sem login' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Serviços que executa">
            @if ($professional->services->isEmpty())
                <p class="text-sm text-muted">Nenhum serviço definido ainda.</p>
            @else
                <ul class="stack stack-sm text-sm">
                    @foreach ($professional->services as $s)
                        <li class="cluster">
                            <x-icon name="scissors" class="icon-sm" /> {{ $s->name }}
                            <span class="text-muted">· {{ $s->durationLabel() }}</span>
                            @unless ($s->is_active)<x-ui.badge>Serviço inativo</x-ui.badge>@endunless
                        </li>
                    @endforeach
                </ul>
            @endif
            @can('professionals.services')
                <x-slot:actions>
                    <x-ui.button :href="route('panel.professionals.services.edit', $professional)" variant="secondary" size="sm" icon="pencil">Alterar</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.card>
    </div>

    @if ($professional->bio)
        <x-ui.card title="Apresentação">
            <p>{{ $professional->bio }}</p>
        </x-ui.card>
    @endif

    <div class="cluster">
        @can('agenda.view')
            @if (auth('web')->user()->can('appointments.view_all') || $professional->user_id === auth('web')->id())
                <x-ui.button :href="route('panel.agenda', ['profissional' => $professional->id])" variant="secondary" icon="calendar-days">Agenda</x-ui.button>
            @endif
        @endcan
        @can('schedule.working_hours')
            <x-ui.button :href="route('panel.schedule.working-hours', $professional)" variant="secondary" icon="clock">Expediente e pausas</x-ui.button>
        @endcan
    </div>
</x-layouts.staff>

<x-layouts.staff :title="$professional->display_name">
    <header class="page-head">
        <div class="stack stack-sm">
            <p class="text-muted">Ficha do profissional</p>
            <h1 class="page-head__title">{{ $professional->display_name }}</h1>
        </div>
    </header>

    <x-ui.card>
        <dl class="summary-list">
            <div><dt>Situação</dt><dd>{{ $professional->is_active ? 'Ativo' : 'Inativo' }}</dd></div>
            <div><dt>Recebe agendamentos</dt><dd>{{ $professional->is_bookable ? 'Sim' : 'Ainda não' }}</dd></div>
            <div><dt>Acesso ao painel</dt><dd>{{ $professional->user?->username ?? 'Sem login' }}</dd></div>
        </dl>
        <p class="text-sm text-muted">Serviços, horários e comissão serão configurados aqui a partir da Fase 4.</p>
    </x-ui.card>
</x-layouts.staff>

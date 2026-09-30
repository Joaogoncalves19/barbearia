<x-layouts.staff title="Auditoria">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Auditoria</h1>
            <p class="text-muted">Quem fez o quê, e quando. Os registros não podem ser alterados nem apagados. Senhas e tokens nunca aparecem; CPF aparece mascarado.</p>
        </div>
    </header>

    @if ($entries->isEmpty())
        <x-ui.empty-state title="Nada registrado ainda" icon="history">Os acessos e as alterações aparecem aqui.</x-ui.empty-state>
    @else
        <x-ui.table caption="Registros de auditoria" caption-hidden stacked>
            <thead>
                <tr><th scope="col">Quando</th><th scope="col">Quem</th><th scope="col">Ação</th><th scope="col">Registro</th><th scope="col">Detalhe</th></tr>
            </thead>
            <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td data-label="Quando" class="numeric">{{ $e->created_at?->timezone(config('barbearia.display_timezone'))->format('d/m/Y H:i:s') }}</td>
                        <td data-label="Quem">{{ $e->actor_label ?? 'Sistema' }}@if ($e->actor_type) <span class="text-muted text-xs">({{ $e->actor_type === 'User' ? 'equipe' : 'cliente' }})</span>@endif</td>
                        <td data-label="Ação"><code>{{ $e->action }}</code></td>
                        <td data-label="Registro">{{ $e->auditable_type ? $e->auditable_type.' #'.$e->auditable_id : '—' }}</td>
                        <td data-label="Detalhe">
                            {{ $e->description ?? '' }}
                            @if ($e->action !== 'created' && $e->changesSummary() !== '')<br><span class="text-sm text-muted">{{ $e->changesSummary() }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        {{ $entries->links() }}
    @endif
</x-layouts.staff>

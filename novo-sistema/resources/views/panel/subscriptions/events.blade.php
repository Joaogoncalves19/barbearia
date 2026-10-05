@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff title="Eventos do Stripe">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.subscriptions.index') }}">Voltar para assinaturas</a>
            <h1 class="page-head__title">Eventos do Stripe</h1>
            <p class="text-muted">Cada evento recebido é guardado uma vez e processado numa transação. Reenvio do mesmo evento não repete nada. Evento com falha é reenviado pelo Stripe e pode ser reprocessado com <code>php artisan app:stripe-reprocess --failed</code>.</p>
        </div>
    </header>

    <form method="GET" action="{{ route('panel.subscriptions.events') }}" class="cluster">
        <x-ui.select name="situacao" label="Mostrar" :options="['todos' => 'Todos', 'problemas' => 'Com falha ou sem assinatura local']" :value="$filter" />
        <x-ui.button type="submit" variant="secondary" icon="funnel">Filtrar</x-ui.button>
    </form>

    <x-ui.card title="Eventos">
        @if ($events->isEmpty())
            <x-ui.empty-state title="Nenhum evento" icon="history">Nenhum evento recebido.</x-ui.empty-state>
        @else
            <x-ui.table caption="Eventos do Stripe" caption-hidden stacked>
                <thead><tr><th scope="col">Recebido</th><th scope="col">Tipo</th><th scope="col">Situação</th><th scope="col">Resultado</th><th scope="col">Tentativas</th><th scope="col">Evento</th></tr></thead>
                <tbody>
                    @foreach ($events as $e)
                        <tr>
                            <td data-label="Recebido">{{ $e->received_at ? BusinessTime::formatLocal($e->received_at) : ($e->processed_at ? BusinessTime::formatLocal($e->processed_at) : '—') }}</td>
                            <td data-label="Tipo">{{ $e->type }}@if ($e->livemode) <x-ui.badge variant="accent">produção</x-ui.badge>@endif</td>
                            <td data-label="Situação"><x-ui.badge :variant="$e->status === 'processed' ? 'success' : ($e->status === 'failed' ? 'danger' : 'warning')">{{ ['processed' => 'Processado', 'failed' => 'Falhou', 'received' => 'Recebido'][$e->status] ?? $e->status }}</x-ui.badge></td>
                            <td data-label="Resultado">{{ ['applied' => 'Aplicado', 'stale' => 'Mais antigo (sem efeito)', 'ignored' => 'Sem efeito', 'unmatched' => 'Sem assinatura local', 'legacy' => 'Sistema antigo'][$e->result] ?? '—' }}@if ($e->last_error)<br><span class="text-sm text-muted">{{ \Illuminate\Support\Str::limit($e->last_error, 120) }}</span>@endif</td>
                            <td data-label="Tentativas" class="numeric">{{ $e->attempts }}</td>
                            <td data-label="Evento" class="text-sm">{{ $e->event_id }}@if ($e->subscription) · <a href="{{ route('panel.subscriptions.show', $e->subscription) }}">assinatura</a>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.staff>

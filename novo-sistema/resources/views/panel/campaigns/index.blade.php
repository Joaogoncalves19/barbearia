@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff title="Campanhas">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Campanhas</h1>
            <p class="text-muted">E-mails de novidades e promoções. Só recebe quem aceitou receber novidades; todo e-mail leva o link de descadastro. O envio sai aos poucos e não atrapalha os e-mails do atendimento.</p>
        </div>
        @can('campaigns.manage')
            <x-ui.button :href="route('panel.campaigns.create')" icon="plus">Nova campanha</x-ui.button>
        @endcan
    </header>

    <x-ui.card title="Lista de campanhas">
        @if ($campaigns->isEmpty())
            <x-ui.empty-state title="Nenhuma campanha" icon="mail">Crie um rascunho, envie um teste e dispare quando estiver pronto.</x-ui.empty-state>
        @else
            <x-ui.table caption="Campanhas" caption-hidden stacked>
                <thead><tr><th scope="col">Campanha</th><th scope="col">Situação</th><th scope="col">Público</th><th scope="col">Enviados</th><th scope="col">Início</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($campaigns as $c)
                        <tr>
                            <td data-label="Campanha"><strong>{{ $c->name ?? $c->subject ?? 'Sem nome' }}</strong>@if ($c->is_legacy)<span class="text-sm text-muted"> · sistema antigo</span>@endif</td>
                            <td data-label="Situação"><x-ui.badge :variant="match ($c->status) { 'sending' => 'info', 'completed' => 'success', 'draft' => 'warning', default => 'neutral' }">{{ $c->statusLabel() }}</x-ui.badge></td>
                            <td data-label="Público" class="numeric">{{ $c->total_recipients }}</td>
                            <td data-label="Enviados" class="numeric">{{ $c->sent_count }}@if ($c->failed_count > 0)<span class="text-sm text-muted"> ({{ $c->failed_count }} falha{{ $c->failed_count === 1 ? '' : 's' }})</span>@endif</td>
                            <td data-label="Início" class="numeric">{{ $c->started_at ? BusinessTime::formatLocal($c->started_at, 'd/m/Y H:i') : '—' }}</td>
                            <td><a href="{{ route('panel.campaigns.show', $c) }}">Abrir<span class="visually-hidden"> {{ $c->name ?? $c->subject }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $campaigns->links() }}
        @endif
    </x-ui.card>
</x-layouts.staff>

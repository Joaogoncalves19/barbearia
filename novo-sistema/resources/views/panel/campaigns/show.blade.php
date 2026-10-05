@php use App\Modules\Scheduling\Support\BusinessTime; @endphp
<x-layouts.staff :title="'Campanha: '.($campaign->name ?? $campaign->subject)">
    <header class="page-head">
        <div class="stack stack-sm">
            <a class="link-arrow text-sm" href="{{ route('panel.campaigns.index') }}">Voltar para campanhas</a>
            <h1 class="page-head__title">{{ $campaign->name ?? $campaign->subject ?? 'Campanha' }}</h1>
            <p><x-ui.badge :variant="match ($campaign->status) { 'sending' => 'info', 'completed' => 'success', 'draft' => 'warning', default => 'neutral' }">{{ $campaign->statusLabel() }}</x-ui.badge></p>
        </div>
        <div class="cluster">
            @if ($campaign->status === 'draft' && ! $campaign->is_legacy)
                @can('campaigns.manage')
                    <x-ui.button :href="route('panel.campaigns.edit', $campaign)" variant="secondary" icon="pencil">Editar</x-ui.button>
                    <form method="POST" action="{{ route('panel.campaigns.test', $campaign) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="mail">Enviar teste para mim</x-ui.button>
                    </form>
                @endcan
                @can('campaigns.send')
                    <x-ui.button icon="play" data-dialog-open="disparar-campanha">Disparar</x-ui.button>
                @endcan
            @endif
            @if (in_array($campaign->status, ['draft', 'sending'], true) && ! $campaign->is_legacy)
                @can('campaigns.send')
                    <x-ui.button variant="danger" icon="x" data-dialog-open="cancelar-campanha">Cancelar campanha</x-ui.button>
                @endcan
            @endif
        </div>
    </header>

    @error('campaign')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @error('confirm')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Resumo">
        <dl class="summary-list">
            <div><dt>Público</dt><dd>{{ $segments[$campaign->segment] ?? ($campaign->segment ?? '—') }}@if (($campaign->segment_params['dias'] ?? null) !== null) ({{ $campaign->segment_params['dias'] }} dias)@endif</dd></div>
            @if ($audience !== null)
                <div><dt>Recebem hoje</dt><dd data-audience>{{ $audience }} cliente(s) que aceitaram receber novidades</dd></div>
            @else
                <div><dt>Destinatários</dt><dd>{{ $campaign->total_recipients }}</dd></div>
                <div><dt>Enviados</dt><dd data-sent>{{ $campaign->sent_count }}</dd></div>
                <div><dt>Não enviados</dt><dd>{{ $campaign->skipped_count }} (descadastro, sem consentimento ou campanha cancelada)</dd></div>
                <div><dt>Falhas</dt><dd>{{ $campaign->failed_count }}</dd></div>
            @endif
            @if ($campaign->started_at)<div><dt>Início</dt><dd>{{ BusinessTime::formatLocal($campaign->started_at, 'd/m/Y H:i') }}</dd></div>@endif
            @if ($campaign->completed_at)<div><dt>Conclusão</dt><dd>{{ BusinessTime::formatLocal($campaign->completed_at, 'd/m/Y H:i') }}</dd></div>@endif
            @if ($campaign->cancelled_at)<div><dt>Cancelada em</dt><dd>{{ BusinessTime::formatLocal($campaign->cancelled_at, 'd/m/Y H:i') }}</dd></div>@endif
        </dl>
    </x-ui.card>

    @if ($preview)
        <x-ui.card title="Prévia (cliente fictício)">
            <div class="stack stack-sm">
                <p><strong>Assunto:</strong> {{ $preview->subject }}</p>
                @foreach ($preview->data['paragraphs'] as $p)
                    <p class="pre-line">{{ $p }}</p>
                @endforeach
                <p class="text-sm text-muted">O e-mail leva o botão “Agendar meu horário” e o link de descadastro no rodapé.</p>
            </div>
        </x-ui.card>
    @endif

    @if ($recent->isNotEmpty())
        <x-ui.card title="Últimos e-mails desta campanha">
            <x-ui.table caption="Últimos e-mails" caption-hidden stacked>
                <thead><tr><th scope="col">Para</th><th scope="col">Situação</th><th scope="col">Quando</th></tr></thead>
                <tbody>
                    @foreach ($recent as $m)
                        <tr>
                            <td data-label="Para">{{ $m->maskedEmail() }}@if ($m->template === 'campaign_test')<span class="text-sm text-muted"> · teste</span>@endif</td>
                            <td data-label="Situação">{{ $m->status->label() }}@if ($m->skip_reason)<span class="text-sm text-muted"> · {{ $m->skip_reason }}</span>@endif</td>
                            <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($m->sent_at ?? $m->queued_at ?? $m->created_at, 'd/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @endif

    @can('campaigns.send')
        @if ($campaign->status === 'draft' && ! $campaign->is_legacy)
            <x-ui.modal id="disparar-campanha" title="Disparar campanha">
                <form method="POST" action="{{ route('panel.campaigns.start', $campaign) }}" class="stack" id="form-disparar">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <p>Vai para {{ $audience ?? 0 }} cliente(s), aos poucos. Depois de disparada, a campanha não pode ser editada.</p>
                    <x-ui.checkbox name="confirm" label="Revisei o texto, o assunto e o público" />
                </form>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Voltar</button>
                    <button type="submit" class="btn" form="form-disparar">Disparar</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
        @if (in_array($campaign->status, ['draft', 'sending'], true) && ! $campaign->is_legacy)
            <x-ui.confirm id="cancelar-campanha" title="Cancelar campanha?" :action="route('panel.campaigns.cancel', $campaign)" confirm-label="Cancelar campanha">
                <p>O que ainda não saiu não sai mais. Os e-mails já enviados não voltam.</p>
            </x-ui.confirm>
        @endif
    @endcan
</x-layouts.staff>

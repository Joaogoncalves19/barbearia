@php
    use App\Modules\Communication\Enums\MessageCategory;
    use App\Modules\Communication\Enums\MessageStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.staff title="E-mails enviados">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">E-mails enviados</h1>
            <p class="text-muted">Registro da fila central: cada e-mail do sistema, com situação, tentativas e motivo quando não foi enviado. O endereço aparece mascarado e o texto não é guardado (é montado na hora do envio).</p>
        </div>
    </header>

    @error('email')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Filtrar">
        <form method="GET" action="{{ route('panel.emails.index') }}" class="cluster" role="search">
            <x-ui.select name="situacao" label="Situação" :options="collect(MessageStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label().' ('.($counts[$s->value] ?? 0).')'])->all()" :value="$filters['situacao'] ?? null" placeholder="Todas" optional />
            <x-ui.select name="tipo" label="Tipo" :options="collect(MessageCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" :value="$filters['tipo'] ?? null" placeholder="Todos" optional />
            <x-ui.select name="modelo" label="Modelo" :options="collect($templates)->map(fn ($t) => $t->label())->all()" :value="$filters['modelo'] ?? null" placeholder="Todos" optional />
            <div><x-ui.button type="submit" variant="secondary" icon="funnel">Filtrar</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card title="Registro">
        @if ($messages->isEmpty())
            <x-ui.empty-state title="Nenhum e-mail" icon="mail">Nada no registro com esses filtros.</x-ui.empty-state>
        @else
            <x-ui.table caption="E-mails" caption-hidden stacked>
                <thead><tr><th scope="col">Quando</th><th scope="col">Modelo</th><th scope="col">Para</th><th scope="col">Situação</th><th scope="col">Tentativas</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($messages as $m)
                        <tr data-email="{{ $m->status->value }}">
                            <td data-label="Quando" class="numeric">{{ BusinessTime::formatLocal($m->sent_at ?? $m->queued_at ?? $m->created_at, 'd/m/Y H:i') }}</td>
                            <td data-label="Modelo">{{ isset($templates[$m->template]) ? $templates[$m->template]->label() : $m->template }}<span class="text-sm text-muted"> · {{ $m->category->label() }}</span></td>
                            <td data-label="Para">{{ $m->maskedEmail() }}</td>
                            <td data-label="Situação">
                                <x-ui.badge :variant="match ($m->status) { MessageStatus::Sent => 'success', MessageStatus::Failed => 'danger', MessageStatus::Queued, MessageStatus::Sending => 'info', default => 'neutral' }">{{ $m->status->label() }}</x-ui.badge>
                                @if ($m->skip_reason)<span class="text-sm text-muted"> {{ $m->skip_reason }}</span>@endif
                                @if ($m->status === MessageStatus::Failed && $m->last_error)<br><span class="text-sm text-muted">{{ \Illuminate\Support\Str::limit($m->last_error, 160) }}</span>@endif
                            </td>
                            <td data-label="Tentativas" class="numeric">{{ $m->attempts }}</td>
                            <td>
                                @if ($m->status === MessageStatus::Failed)
                                    @can('communications.retry')
                                        <form method="POST" action="{{ route('panel.emails.retry', $m) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="undo-2">Reenviar<span class="visually-hidden"> e-mail para {{ $m->maskedEmail() }}</span></x-ui.button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            {{ $messages->links() }}
        @endif
    </x-ui.card>

    <x-ui.card title="Modelos de e-mail">
        <p class="text-sm text-muted">Prévia com dados fictícios, no mesmo layout do envio.</p>
        <ul class="stack stack-sm" role="list">
            @foreach ($templates as $key => $t)
                <li class="cluster">
                    <span>{{ $t->label() }}</span>
                    <x-ui.badge>{{ $t->category()->label() }}</x-ui.badge>
                    <a class="link-arrow text-sm" href="{{ route('panel.emails.preview', $key) }}" target="_blank" rel="noopener">Ver prévia<span class="visually-hidden"> de {{ $t->label() }}</span></a>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</x-layouts.staff>

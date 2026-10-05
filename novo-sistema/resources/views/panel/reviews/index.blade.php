@php
    use App\Modules\Reviews\Enums\ReviewStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.staff title="Avaliações">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Avaliações</h1>
            <p class="text-muted">
                @if ($all)
                    Avaliações dos clientes sobre atendimentos concluídos. Só aparecem para outras pessoas depois de aprovadas.
                @else
                    Avaliações publicadas dos seus atendimentos.
                @endif
            </p>
        </div>
    </header>

    @error('review')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @error('reason')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror
    @error('body')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    @if ($all)
        <nav class="cluster" aria-label="Filtro de avaliações">
            @foreach (['pending' => 'Aguardando revisão ('.$pendingCount.')', 'approved' => 'Publicadas', 'rejected' => 'Recusadas', 'todas' => 'Todas'] as $chave => $rotulo)
                <x-ui.button :href="route('panel.reviews.index', ['situacao' => $chave])" size="sm" :variant="$filter === $chave ? 'primary' : 'secondary'" :aria-current="$filter === $chave ? 'page' : null">{{ $rotulo }}</x-ui.button>
            @endforeach
        </nav>
    @endif

    @if ($reviews->isEmpty())
        <x-ui.empty-state title="Nenhuma avaliação aqui" icon="star">Quando os clientes avaliarem, as avaliações aparecem nesta lista.</x-ui.empty-state>
    @else
        <div class="stack">
            @foreach ($reviews as $r)
                <x-ui.card>
                    <article class="stack stack-sm" data-review="{{ $r->id }}">
                        <div class="cluster">
                            <strong aria-label="Nota {{ $r->rating }} de 5">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</strong>
                            <x-ui.badge :variant="match ($r->status) { ReviewStatus::Approved => 'success', ReviewStatus::Rejected => 'neutral', default => 'warning' }">{{ $r->status->label() }}</x-ui.badge>
                            @if ($r->is_featured)<x-ui.badge variant="info">Destaque</x-ui.badge>@endif
                            @if ($r->is_legacy)<x-ui.badge>Sistema antigo</x-ui.badge>@endif
                        </div>
                        <p class="text-sm text-muted">
                            {{ $all ? ($r->customer->name ?? 'Cliente removido') : (trim(explode(' ', (string) ($r->customer->name ?? 'Cliente'))[0])) }}
                            · {{ $r->professional->display_name ?? 'Sem profissional' }}
                            @if ($r->attendance) · {{ $r->attendance->code }}@endif
                            @if ($r->reviewed_at) · {{ BusinessTime::formatLocal($r->reviewed_at, 'd/m/Y H:i') }}@endif
                        </p>
                        @if ($r->comment)
                            <p class="pre-line">{{ $r->comment }}</p>
                        @else
                            <p class="text-sm text-muted">Sem comentário.</p>
                        @endif
                        @if ($r->status === ReviewStatus::Rejected && $r->moderation_reason)
                            <p class="text-sm"><strong>Motivo da recusa:</strong> {{ $r->moderation_reason }}</p>
                        @endif
                        @if ($r->reply)
                            <p class="text-sm"><strong>Resposta da barbearia:</strong> <span class="pre-line">{{ $r->reply->body }}</span></p>
                        @endif

                        <div class="cluster">
                            @can('reviews.moderate')
                                @if ($r->status !== ReviewStatus::Approved)
                                    <form method="POST" action="{{ route('panel.reviews.approve', $r) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" icon="check">Aprovar<span class="visually-hidden"> avaliação {{ $r->id }}</span></x-ui.button>
                                    </form>
                                @endif
                                @if ($r->status !== ReviewStatus::Rejected)
                                    <x-ui.button size="sm" variant="secondary" icon="x" data-dialog-open="recusar-{{ $r->id }}">Recusar<span class="visually-hidden"> avaliação {{ $r->id }}</span></x-ui.button>
                                @endif
                                @if ($r->status === ReviewStatus::Approved)
                                    <form method="POST" action="{{ route('panel.reviews.feature', $r) }}">
                                        @csrf
                                        <input type="hidden" name="featured" value="{{ $r->is_featured ? 0 : 1 }}">
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="sparkles">{{ $r->is_featured ? 'Tirar destaque' : 'Destacar' }}<span class="visually-hidden"> avaliação {{ $r->id }}</span></x-ui.button>
                                    </form>
                                @endif
                            @endcan
                        </div>

                        @can('reviews.reply')
                            @if ($r->status === ReviewStatus::Approved)
                                <form method="POST" action="{{ route('panel.reviews.reply', $r) }}" class="stack stack-sm">
                                    @csrf
                                    <x-ui.textarea name="body" :id="'resposta-'.$r->id" :label="$r->reply ? 'Alterar resposta' : 'Responder'" :value="$r->reply?->body" rows="2" :maxlength="1000" />
                                    <div><x-ui.button type="submit" size="sm" variant="secondary" icon="message-circle">Publicar resposta</x-ui.button></div>
                                </form>
                            @endif
                        @endcan
                    </article>
                </x-ui.card>

                @can('reviews.moderate')
                    @if ($r->status !== ReviewStatus::Rejected)
                        <x-ui.modal :id="'recusar-'.$r->id" title="Recusar avaliação">
                            <form method="POST" action="{{ route('panel.reviews.reject', $r) }}" class="stack" id="form-recusar-{{ $r->id }}">
                                @csrf
                                <p>A avaliação não aparece para ninguém. O motivo fica registrado na auditoria.</p>
                                <x-ui.input name="reason" :id="'motivo-'.$r->id" label="Motivo" maxlength="255" />
                            </form>
                            <x-slot:footer>
                                <button type="button" class="btn btn--secondary" data-dialog-close>Cancelar</button>
                                <button type="submit" class="btn btn--danger" form="form-recusar-{{ $r->id }}">Recusar</button>
                            </x-slot:footer>
                        </x-ui.modal>
                    @endif
                @endcan
            @endforeach
        </div>
        {{ $reviews->links() }}
    @endif
</x-layouts.staff>

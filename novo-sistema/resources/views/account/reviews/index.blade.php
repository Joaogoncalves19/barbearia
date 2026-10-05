@php
    use App\Modules\Reviews\Enums\ReviewStatus;
    use App\Modules\Scheduling\Support\BusinessTime;
@endphp
<x-layouts.account title="Avaliações">
    <header class="stack stack-sm">
        <h1 class="h2">Avaliações</h1>
        <p class="text-muted">Conte como foi o seu atendimento. Sua avaliação aparece para outras pessoas depois da revisão da barbearia.</p>
    </header>

    <x-ui.card title="Para avaliar">
        @if ($pending->isEmpty())
            <x-ui.empty-state title="Nada para avaliar agora" icon="star">Atendimentos concluídos nos últimos 30 dias aparecem aqui.</x-ui.empty-state>
        @else
            <ul class="stack stack-sm" role="list">
                @foreach ($pending as $at)
                    <li class="cluster">
                        <span>{{ $at->completed_at ? BusinessTime::formatLocal($at->completed_at, 'd/m/Y') : '' }} · {{ $at->professional->display_name ?? 'Profissional' }} · {{ $at->code }}</span>
                        <x-ui.button :href="route('account.reviews.create', $at)" size="sm" variant="accent" icon="star">Avaliar<span class="visually-hidden"> o atendimento {{ $at->code }}</span></x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card title="Suas avaliações">
        @if ($reviews->isEmpty())
            <x-ui.empty-state title="Nenhuma avaliação ainda" icon="star">Quando você avaliar um atendimento, ele aparece aqui.</x-ui.empty-state>
        @else
            <ul class="stack" role="list">
                @foreach ($reviews as $r)
                    <li class="stack stack-sm" data-review="{{ $r->id }}">
                        <div class="cluster">
                            <strong aria-label="Nota {{ $r->rating }} de 5">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</strong>
                            <span class="text-sm text-muted">{{ $r->professional->display_name ?? '' }}{{ $r->reviewed_at ? ' · '.BusinessTime::formatLocal($r->reviewed_at, 'd/m/Y') : '' }}</span>
                            <x-ui.badge :variant="match ($r->status) { ReviewStatus::Approved => 'success', ReviewStatus::Rejected => 'neutral', default => 'warning' }">{{ $r->status === ReviewStatus::Approved ? 'Publicada' : ($r->status === ReviewStatus::Rejected ? 'Não publicada' : 'Em revisão') }}</x-ui.badge>
                        </div>
                        @if ($r->comment)
                            <p class="pre-line">{{ $r->comment }}</p>
                        @endif
                        @if ($r->reply && $r->status === ReviewStatus::Approved)
                            <p class="text-sm"><strong>Resposta da barbearia:</strong> <span class="pre-line">{{ $r->reply->body }}</span></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</x-layouts.account>

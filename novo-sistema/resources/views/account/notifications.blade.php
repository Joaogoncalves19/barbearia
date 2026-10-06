@php
    use App\Modules\Scheduling\Support\BusinessTime;
    $tipos = ['reminder' => 'Lembrete', 'review_request' => 'Avaliação', 'subscription' => 'Assinatura'];
@endphp
<x-layouts.account title="Avisos">
    <header class="cluster">
        <h1 class="h2">Avisos</h1>
        @if ($notifications->contains(fn ($n) => $n->read_at === null))
            <form method="POST" action="{{ route('account.notifications.read') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" icon="check">Marcar todos como lidos</x-ui.button>
            </form>
        @endif
    </header>

    {{-- Fase 12: avisos da conta sao so do servico (transacionais). Novidades e promocoes (marketing) so por e-mail, com consentimento. --}}
    <p class="text-sm text-muted" data-notice-kinds>Aqui ficam só avisos do serviço: lembretes do seu horário, pedidos de avaliação e avisos da assinatura. Novidades e promoções chegam só por e-mail, se você escolher recebê-las em <a href="{{ route('account.profile.edit') }}#emails">Meus dados</a>.</p>

    @if ($notifications->isEmpty())
        <x-ui.empty-state title="Nenhum aviso" icon="bell">Lembretes do seu horário, pedidos de avaliação e avisos da assinatura aparecem aqui.</x-ui.empty-state>
    @else
        <x-ui.card>
            <ul class="stack" role="list">
                @foreach ($notifications as $n)
                    <li class="stack stack-sm" data-notification="{{ $n->kind }}">
                        <div class="cluster">
                            <x-ui.badge>{{ $tipos[$n->kind] ?? 'Aviso' }}</x-ui.badge>
                            @if ($n->read_at === null)<x-ui.badge variant="info">Novo</x-ui.badge>@endif
                            <span class="text-sm text-muted">{{ $n->created_at ? BusinessTime::formatLocal($n->created_at, 'd/m/Y H:i') : '' }}</span>
                        </div>
                        <p>{{ $n->message }}</p>
                        @if ($n->link && str_starts_with($n->link, url('/')))
                            <a class="link-arrow text-sm" href="{{ $n->link }}">Abrir</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
        {{ $notifications->links() }}
    @endif
</x-layouts.account>

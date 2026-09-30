<x-layouts.booking :title="'Agendar '.$service->name" :step="2">
    <header class="stack stack-sm">
        <a class="link-arrow text-sm" href="{{ route('booking.services') }}">Trocar serviço</a>
        <h1 class="h2">Com quem?</h1>
        <p class="text-muted">{{ $service->name }} · {{ $service->durationLabel() }} · {{ $service->price()->format() }}</p>
    </header>

    <div class="stack stack-sm">
        <a class="card card--link cluster" href="{{ route('booking.slots', ['service' => $service, 'profissional' => 'qualquer']) }}">
            <span class="stack stack-sm">
                <strong>Sem preferência</strong>
                <span class="text-sm text-muted">Mostra todos os horários livres, com qualquer profissional.</span>
            </span>
        </a>
        @forelse ($professionals as $p)
            <a class="card card--link cluster" href="{{ route('booking.slots', ['service' => $service, 'profissional' => $p->slug]) }}">
                <x-ui.avatar :name="$p->display_name" :src="$p->photoUrl()" />
                <span class="stack stack-sm">
                    <strong>{{ $p->display_name }}</strong>
                    @if ($p->headline)<span class="text-sm text-muted">{{ $p->headline }}</span>@endif
                </span>
            </a>
        @empty
            <x-ui.empty-state title="Ninguém disponível" icon="users">Nenhum profissional está fazendo este serviço agora.</x-ui.empty-state>
        @endforelse
    </div>
</x-layouts.booking>

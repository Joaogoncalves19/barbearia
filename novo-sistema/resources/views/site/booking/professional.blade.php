<x-layouts.booking :title="'Agendar '.$service->name" :step="2">
    <header class="booking-head">
        <a class="link-arrow back-link" href="{{ route('booking.services') }}"><x-icon name="chevron-left" /> Trocar serviço</a>
        <h1 class="h1 caps">Com quem?</h1>
        <p class="booking-pick"><strong>{{ $service->name }}</strong> <span>{{ $service->durationLabel() }}</span> <span class="figure figure--sm">{{ $service->price()->format() }}</span></p>
    </header>

    <ul class="choice-list" role="list">
        <li>
            <a class="choice" href="{{ route('booking.slots', ['service' => $service, 'profissional' => 'qualquer']) }}">
                <span class="choice__mark" aria-hidden="true"><x-icon name="users" /></span>
                <span class="choice__text">
                    <strong>Sem preferência</strong>
                    <span class="text-sm text-muted">Mostra todos os horários livres, com qualquer profissional.</span>
                </span>
                <x-icon name="chevron-right" />
            </a>
        </li>
        @forelse ($professionals as $p)
            <li>
                <a class="choice" href="{{ route('booking.slots', ['service' => $service, 'profissional' => $p->slug]) }}">
                    <x-ui.avatar :name="$p->display_name" :src="$p->photoUrl()" size="lg" />
                    <span class="choice__text">
                        <strong>{{ $p->display_name }}</strong>
                        @if ($p->headline)<span class="text-sm text-muted">{{ $p->headline }}</span>@endif
                    </span>
                    <x-icon name="chevron-right" />
                </a>
            </li>
        @empty
            <li><x-ui.empty-state title="Ninguém disponível" icon="users">Nenhum profissional está fazendo este serviço agora. Escolha outro serviço.</x-ui.empty-state></li>
        @endforelse
    </ul>
</x-layouts.booking>

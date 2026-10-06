<x-layouts.booking title="Agendar" :step="1">
    <header class="booking-head">
        <h1 class="h1 caps">Escolha o serviço</h1>
        <p class="text-muted">Você vê os horários livres antes de entrar na sua conta.</p>
    </header>

    @forelse ($groups as $g)
        <section class="stack" aria-labelledby="cat-{{ $g['category']?->id ?? 0 }}">
            <h2 class="eyebrow eyebrow--plain" id="cat-{{ $g['category']?->id ?? 0 }}">{{ $g['category']?->name ?? 'Outros' }}</h2>
            <ul class="service-list service-list--compact" role="list">
                @foreach ($g['services'] as $s)
                    @include('site.partials.service-row', ['s' => $s, 'href' => route('booking.professional', $s), 'cta' => 'Escolher'])
                @endforeach
            </ul>
        </section>
    @empty
        <x-ui.empty-state title="Agenda indisponível" icon="calendar">No momento não há serviços para agendar pelo site. Fale com a barbearia pelo telefone ou WhatsApp.</x-ui.empty-state>
    @endforelse
</x-layouts.booking>

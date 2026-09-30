<x-layouts.booking title="Agendar" :step="1">
    <header class="stack stack-sm">
        <h1 class="h2">Escolha o serviço</h1>
        <p class="text-muted">Você vê os horários livres antes de entrar na sua conta.</p>
    </header>

    @forelse ($groups as $g)
        <section class="stack stack-sm" aria-labelledby="cat-{{ $g['category']?->id ?? 0 }}">
            <h2 class="h3" id="cat-{{ $g['category']?->id ?? 0 }}">{{ $g['category']?->name ?? 'Outros' }}</h2>
            <div class="stack stack-sm">
                @foreach ($g['services'] as $s)
                    <a class="card card--link cluster" href="{{ route('booking.professional', $s) }}">
                        <span class="stack stack-sm">
                            <strong>{{ $s->name }}</strong>
                            <span class="text-sm text-muted">{{ $s->durationLabel() }}@if ($s->description) · {{ $s->description }}@endif</span>
                        </span>
                        <strong class="numeric">{{ $s->price()->format() }}</strong>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <x-ui.empty-state title="Agenda indisponível" icon="calendar">No momento não há serviços para agendar pelo site.</x-ui.empty-state>
    @endforelse
</x-layouts.booking>

{{-- SERVICOS E PRECOS: o mesmo catalogo do painel, so o que esta ativo e publicado. --}}
<x-site.page title="Serviços e preços" :description="'Serviços, preços e duração na '.$cfg->name().'. Agende online.'" current="services">
    <header class="masthead" aria-labelledby="servicos-titulo">
        <div class="container masthead__inner">
            <p class="eyebrow">Serviços</p>
            <h1 id="servicos-titulo" class="display caps masthead__title">Serviços e preços</h1>
            <p class="lead">Preço e duração de cada serviço. Escolha um para ver os horários livres; o valor daqui é o que fica no seu agendamento.</p>
            @if (count($groups) > 1)
                <nav class="category-chips" aria-label="Categorias">
                    @foreach ($groups as $g)
                        <a href="#cat-{{ $g['category']?->slug ?? 'outros' }}">{{ $g['category']?->name ?? 'Outros' }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </header>

    <div class="container page-body">
        @forelse ($groups as $g)
            <section class="chapter chapter--flush" id="cat-{{ $g['category']?->slug ?? 'outros' }}" aria-labelledby="titulo-cat-{{ $g['category']?->id ?? 0 }}">
                <div class="chapter__grid">
                    <header class="chapter__head">
                        <p class="chapter__index" aria-hidden="true"></p>
                        <h2 class="h2 caps" id="titulo-cat-{{ $g['category']?->id ?? 0 }}">{{ $g['category']?->name ?? 'Outros' }}</h2>
                        @if ($g['category']?->description)<p class="text-muted">{{ $g['category']->description }}</p>@endif
                    </header>
                    <ul class="service-list" role="list">
                        @foreach ($g['services'] as $s)
                            @include('site.partials.service-row', ['s' => $s, 'href' => route('booking.professional', $s), 'cta' => 'Ver horários', 'extra' => 'de '.$s->name, 'featured' => true])
                        @endforeach
                    </ul>
                </div>
            </section>
        @empty
            <x-ui.empty-state title="Serviços em breve" icon="scissors">Os serviços aparecem aqui assim que forem publicados pela barbearia.</x-ui.empty-state>
        @endforelse
    </div>
</x-site.page>

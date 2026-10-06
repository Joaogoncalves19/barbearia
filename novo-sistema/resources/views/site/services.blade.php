{{-- SERVICOS E PRECOS (Fase 11): o mesmo catalogo do painel, so o que esta ativo e publicado. --}}
<x-site.page title="Serviços e preços" :description="'Serviços, preços e duração na '.$cfg->name().'. Agende online.'" current="services">
    <section class="section section--tight page-head-site" aria-labelledby="servicos-titulo">
        <div class="container stack">
            <p class="eyebrow">Serviços</p>
            <h1 id="servicos-titulo" class="h1">Serviços e preços</h1>
            <p class="lead">Preço e duração de cada serviço. Escolha um para ver os horários livres.</p>
            @if (count($groups) > 1)
                <nav class="category-chips" aria-label="Categorias">
                    @foreach ($groups as $g)
                        <a href="#cat-{{ $g['category']?->slug ?? 'outros' }}">{{ $g['category']?->name ?? 'Outros' }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </section>
    <div class="barber-stripe barber-stripe--thin" aria-hidden="true"></div>

    <section class="section section--tight">
        <div class="container">
            @forelse ($groups as $g)
                <section class="menu-section" id="cat-{{ $g['category']?->slug ?? 'outros' }}" aria-labelledby="titulo-cat-{{ $g['category']?->id ?? 0 }}">
                    <h2 class="menu-section__title" id="titulo-cat-{{ $g['category']?->id ?? 0 }}">{{ $g['category']?->name ?? 'Outros' }}</h2>
                    @if ($g['category']?->description)<p class="text-muted">{{ $g['category']->description }}</p>@endif
                    <ul class="menu-board" role="list">
                        @foreach ($g['services'] as $s)
                            <li class="menu-line" data-service="{{ $s->slug }}">
                                <a class="menu-line__link @if ($s->image_path) menu-line__link--photo @endif" href="{{ route('booking.professional', $s) }}">
                                    @if ($s->image_path)
                                        <span class="menu-line__thumb"><x-site.img :path="$s->image_path" alt="" sizes="6rem" /></span>
                                    @endif
                                    <span class="menu-line__body">
                                        <span class="menu-line__top">
                                            <span class="menu-line__name">{{ $s->name }}@if ($s->is_featured) <span class="tag">Destaque</span>@endif</span>
                                            <span class="menu-line__dots" aria-hidden="true"></span>
                                            <span class="menu-line__price">{{ $s->price()->format() }}</span>
                                        </span>
                                        <span class="menu-line__meta">
                                            <span><x-icon name="clock" /> {{ $s->durationLabel() }}</span>
                                            <span class="menu-line__cta">Ver horários<span class="visually-hidden"> de {{ $s->name }}</span> <x-icon name="arrow-right" /></span>
                                        </span>
                                        @if ($s->description)<span class="menu-line__desc">{{ $s->description }}</span>@endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <x-ui.empty-state title="Serviços em breve" icon="scissors">Os serviços aparecem aqui assim que forem publicados.</x-ui.empty-state>
            @endforelse
        </div>
    </section>
</x-site.page>

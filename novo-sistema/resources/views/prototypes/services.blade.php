{{-- TELA DE REFERENCIA 2 — Pagina de servicos. Valores de EXEMPLO. --}}
@php $agendar = route('prototypes.booking', $q); @endphp
<x-layouts.site title="Serviços (referência)" description="Serviços, duração e preços."
    :direction="$direcao" :brand="$brand" :nav="$siteNav" :booking-url="$agendar"
    whatsapp-url="#whatsapp-exemplo" :home-url="route('prototypes.home', $q)" prototype>

    <section class="section section--tight" aria-labelledby="titulo-servicos">
        <div class="container stack stack-lg">
            <x-ui.breadcrumbs :items="[['label' => 'Início', 'href' => route('prototypes.home', $q)], ['label' => 'Serviços']]" />
            <header class="stack">
                <p class="eyebrow">Serviços e preços</p>
                <h1 id="titulo-servicos" class="h1">Escolha o que combina com você.</h1>
                <p class="lead">Todos os valores e durações ficam visíveis antes do agendamento. Combos custam menos que os serviços separados.</p>
                <p><x-ui.badge variant="sample">Valores e descrições de exemplo</x-ui.badge></p>
            </header>
        </div>
    </section>

    <nav class="category-nav" aria-label="Categorias de serviço">
        <div class="container">
            <div class="tabs__list">
                @foreach ($services as $grupo)
                    <a class="tabs__tab" href="#cat-{{ Str::slug($grupo['category']) }}" @if ($loop->first) aria-current="page" @endif>{{ $grupo['category'] }}</a>
                @endforeach
            </div>
        </div>
    </nav>

    <div class="section section--tight">
        <div class="container menu-columns">
            @foreach ($services as $grupo)
                <section class="menu-group" id="cat-{{ Str::slug($grupo['category']) }}" aria-labelledby="t-{{ Str::slug($grupo['category']) }}">
                    <h2 class="menu-group__title" id="t-{{ Str::slug($grupo['category']) }}">{{ $grupo['category'] }} <span class="text-muted">{{ count($grupo['items']) }}</span></h2>
                    @foreach ($grupo['items'] as $item)
                        <article class="menu-item">
                            <h3 class="menu-item__name">{{ $item['name'] }}</h3>
                            <p class="menu-item__price">{{ $item['price']->format() }}</p>
                            <p class="menu-item__desc">{{ $item['description'] }}</p>
                            <p class="menu-item__meta">
                                <span class="cluster"><x-icon name="clock" /> {{ $item['minutes'] }} min</span>
                                <a class="link-arrow" href="{{ $agendar }}">Agendar <x-icon name="arrow-right" /></a>
                            </p>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>
    </div>

    <section class="section" aria-labelledby="duvidas">
        <div class="container-narrow stack">
            <h2 id="duvidas" class="h3">Não sabe o que pedir?</h2>
            <p class="text-muted">Agende um corte e converse com o profissional na cadeira — ele ajusta na hora. Pagamento no local (formas de pagamento a definir).</p>
            <div><x-ui.button :href="$agendar" variant="accent" icon="calendar">Agendar horário</x-ui.button></div>
        </div>
    </section>

    <x-slot:footer>
        @include('prototypes.partials.site-footer')
    </x-slot:footer>
</x-layouts.site>

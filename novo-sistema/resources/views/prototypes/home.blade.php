{{-- TELA DE REFERENCIA 1 — Home publica. Dados e fotos de EXEMPLO. --}}
@php
    $agendar = route('prototypes.booking', $q);
    $servicosUrl = route('prototypes.services', $q);
    $destaques = collect($services)->flatMap(fn ($c) => collect($c['items'])->map(fn ($i) => $i + ['category' => $c['category']]))
        ->whereIn('id', ['corte', 'degrade', 'barba', 'combo'])->values();
@endphp
<x-layouts.site
    title="Home (referência)"
    description="Barbearia contemporânea: corte, barba e cuidado, com agendamento online."
    :direction="$direcao" :brand="$brand" :nav="$siteNav" :booking-url="$agendar"
    whatsapp-url="#whatsapp-exemplo" :home-url="route('prototypes.home', $q)" prototype>

    {{-- HERO: o que e, para quem, onde + agendar. Foto real de ambiente e obrigatoria. --}}
    <section class="hero" aria-labelledby="hero-titulo">
        <div class="hero__media">
            <x-ui.photo ratio="wide" placeholder="Ambiente da barbearia com luz quente (foto horizontal, alta resolução)" eager />
        </div>
        <div class="container hero__content">
            <p class="eyebrow">Barbearia no Centro · desde 20XX</p>
            <h1 id="hero-titulo" class="display hero__title">Corte, barba e <em>boa conversa</em>.</h1>
            <p class="lead">Profissionais que ouvem antes de cortar, ritual de toalha quente e horário marcado em menos de um minuto.</p>
            <div class="hero__actions">
                <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Agendar horário</x-ui.button>
                <a class="link-arrow" href="{{ $servicosUrl }}">Ver serviços e preços <x-icon name="arrow-right" /></a>
            </div>
        </div>
    </section>

    {{-- FAIXA PRATICA: so informacao real. Nota media aparece apenas com avaliacoes reais. --}}
    <section class="info-strip" aria-label="Informações rápidas">
        <div class="container info-strip__list">
            <p class="info-strip__item"><x-icon name="clock" /><span><strong>Aberto hoje até 20h</strong>Seg a sáb · veja todos os horários</span></p>
            <p class="info-strip__item"><x-icon name="map-pin" /><span><strong>Rua Exemplo, 123 — Centro</strong>Endereço de exemplo</span></p>
            <p class="info-strip__item"><x-icon name="star" /><span><strong>Nota média das avaliações</strong>Exibida quando houver avaliações reais</span></p>
        </div>
    </section>

    {{-- SERVICOS: menu com preco e duracao, sem "clique para ver". --}}
    <section class="section" id="servicos" aria-labelledby="servicos-titulo">
        <div class="container">
            <header class="section__head section__head--split">
                <div class="stack stack-sm">
                    <p class="eyebrow">Serviços</p>
                    <h2 id="servicos-titulo" class="h2">Preço e tempo, antes de você decidir.</h2>
                </div>
                <x-ui.badge variant="sample">Valores de exemplo</x-ui.badge>
            </header>
            <div class="menu-columns">
                @foreach ($destaques->groupBy('category') as $categoria => $itens)
                    <div class="menu-group">
                        <h3 class="menu-group__title">{{ $categoria }}</h3>
                        @foreach ($itens as $item)
                            <article class="menu-item">
                                <h4 class="menu-item__name">{{ $item['name'] }}</h4>
                                <p class="menu-item__price">{{ $item['price']->format() }}</p>
                                <p class="menu-item__desc">{{ $item['description'] }}</p>
                                <p class="menu-item__meta"><span class="cluster"><x-icon name="clock" /> {{ $item['minutes'] }} min</span></p>
                            </article>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <p class="section__more"><a class="link-arrow" href="{{ $servicosUrl }}">Todos os serviços <x-icon name="arrow-right" /></a></p>
        </div>
    </section>

    {{-- EQUIPE: pessoas reais geram confianca. Carrossel horizontal no celular. --}}
    <section class="section" id="equipe" aria-labelledby="equipe-titulo" data-superficie="clara">
        <div class="container">
            <header class="section__head section__head--split">
                <div class="stack stack-sm">
                    <p class="eyebrow">Equipe</p>
                    <h2 id="equipe-titulo" class="h2">Escolha quem vai cuidar de você.</h2>
                </div>
                <x-ui.badge variant="sample">Nomes de exemplo</x-ui.badge>
            </header>
            <ul class="team" role="list">
                @foreach ($pros as $pro)
                    <li class="pro-card">
                        <x-ui.photo ratio="portrait" :placeholder="'Retrato de '.$pro['name'].' no posto de trabalho'" />
                        <div>
                            <h3 class="pro-card__name">{{ $pro['name'] }}</h3>
                            <p class="pro-card__role">{{ $pro['role'] }}</p>
                        </div>
                        <a class="link-arrow" href="{{ $agendar }}">Agendar com {{ strtok($pro['name'], ' ') }} <x-icon name="arrow-right" /></a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- SOBRE + GALERIA: so aparece com conteudo real (fotos de trabalhos e do espaco). --}}
    <section class="section" id="sobre" aria-labelledby="sobre-titulo">
        <div class="container split">
            <div class="stack stack-lg">
                <div class="stack">
                    <p class="eyebrow">A barbearia</p>
                    <h2 id="sobre-titulo" class="h2">Um lugar para sentar, conversar e sair bem.</h2>
                    <p class="lead">Texto de exemplo: conte aqui a história da casa em duas ou três frases — quem abriu, o que valoriza e como é estar ali.</p>
                </div>
                <ul class="values" role="list">
                    <li class="values__item"><x-icon name="coffee" /><div><h3>Café e água por conta da casa</h3><p>Diferencial de exemplo — trocar pelos reais.</p></div></li>
                    <li class="values__item"><x-icon name="armchair" /><div><h3>Horário respeitado</h3><p>Agenda online com confirmação e lembrete.</p></div></li>
                    <li class="values__item"><x-icon name="badge-check" /><div><h3>Ferramentas esterilizadas</h3><p>Diferencial de exemplo — trocar pelos reais.</p></div></li>
                </ul>
            </div>
            <div class="gallery" aria-label="Galeria de trabalhos">
                <x-ui.photo ratio="portrait" placeholder="Degradê finalizado (vertical)" />
                <x-ui.photo ratio="square" placeholder="Mãos com navalha em ação" />
                <x-ui.photo ratio="square" placeholder="Detalhe da bancada e ferramentas" />
                <x-ui.photo ratio="square" placeholder="Barba finalizada" />
                <x-ui.photo ratio="square" placeholder="Fachada da barbearia" />
            </div>
        </div>
    </section>

    {{-- AVALIACOES: somente avaliacoes reais e destacadas pelo dono (aqui, exemplos). --}}
    <section class="section" id="avaliacoes" aria-labelledby="avaliacoes-titulo" data-superficie="clara">
        <div class="container">
            <header class="section__head section__head--split">
                <div class="stack stack-sm">
                    <p class="eyebrow">Avaliações</p>
                    <h2 id="avaliacoes-titulo" class="h2">O que dizem depois do espelho.</h2>
                </div>
                <x-ui.badge variant="sample">Depoimentos de exemplo</x-ui.badge>
            </header>
            <div class="quotes">
                @foreach ($testimonials as $t)
                    <figure class="quote">
                        <blockquote><p>“{{ $t['text'] }}”</p></blockquote>
                        <figcaption>
                            <span class="stars" role="img" aria-label="5 de 5 estrelas">@for ($i = 0; $i < 5; $i++)<x-icon name="star" />@endfor</span>
                            <span>{{ $t['author'] }} · {{ $t['service'] }}</span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>

    {{-- LOCALIZACAO E HORARIOS --}}
    <section class="section" id="local" aria-labelledby="local-titulo">
        <div class="container split">
            <div class="stack stack-lg">
                <div class="stack">
                    <p class="eyebrow">Como chegar</p>
                    <h2 id="local-titulo" class="h2">Rua Exemplo, 123 — Centro.</h2>
                    <p class="text-muted">Endereço, referência e estacionamento de exemplo.</p>
                </div>
                <dl class="hours">
                    @foreach ($hours as $h)
                        <div @class(['is-today' => $h['today']])>
                            <dt>{{ $h['day'] }}@if ($h['today']) <span class="visually-hidden">(hoje)</span>@endif</dt>
                            <dd>{{ $h['hours'] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="cluster">
                    <x-ui.button href="#rota-exemplo" variant="secondary" icon="map-pin">Abrir no mapa</x-ui.button>
                    <x-ui.button href="#whatsapp-exemplo" variant="secondary" icon="message-circle">WhatsApp</x-ui.button>
                </div>
            </div>
            <x-ui.photo ratio="landscape" placeholder="Mapa (carregado só ao clicar, para não pesar a página)" />
        </div>
    </section>

    {{-- CTA FINAL --}}
    <section class="section" aria-labelledby="cta-titulo">
        <div class="container cta-final">
            <h2 id="cta-titulo" class="h1">Sua cadeira está livre hoje?</h2>
            <p class="lead">Veja os horários disponíveis sem precisar criar conta.</p>
            <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Ver horários</x-ui.button>
        </div>
    </section>

    <x-slot:footer>
        @include('prototypes.partials.site-footer')
    </x-slot:footer>
</x-layouts.site>

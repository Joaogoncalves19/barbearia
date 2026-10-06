{{--
    INICIO do site publico (Fase 11; home.md). Ordem pensada para o celular:
    topo (o que e, onde, agendar) -> informacoes praticas -> servicos ->
    assinatura -> equipe -> a barbearia -> galeria -> avaliacoes -> como
    chegar -> chamada final. Cada bloco so aparece com conteudo REAL.
--}}
@php
    use App\Modules\SiteContent\Services\PublicSite;
    use App\Modules\SiteContent\Support\OpeningHours;
    $agendar = route('booking.services');
    $nomeCurto = $cfg->name();
@endphp
<x-site.page :json-ld="$jsonLd" :canonical="route('home')">
    {{-- TOPO: o que e, onde e o botao de agendar. Com foto real ou, sem ela, versao tipografica. --}}
    <section @class(['hero', 'hero--photo' => $hero, 'hero--type' => ! $hero]) aria-labelledby="inicio-titulo">
        @if ($hero)
            <div class="hero__media">
                <x-site.img :path="$hero->path" :alt="$hero->alt" sizes="100vw" eager class="hero__img" />
            </div>
        @else
            <div class="hero__ornaments" aria-hidden="true">
                <x-site.ornament name="scissors" class="hero__ornament hero__ornament--a" />
                <x-site.ornament name="comb" class="hero__ornament hero__ornament--b" />
            </div>
        @endif
        <div class="container hero__content">
            <p class="eyebrow hero__eyebrow"><span class="pole" aria-hidden="true"></span>Barbearia{{ $cfg->has('neighborhood') ? ' · '.$cfg->get('neighborhood') : '' }}</p>
            <h1 id="inicio-titulo" class="display hero__title">{{ $cfg->has('tagline') ? $cfg->get('tagline') : $nomeCurto }}</h1>
            @if ($cfg->has('tagline'))
                <p class="hero__name">{{ $nomeCurto }}</p>
            @endif
            @if ($cfg->has('hero_subtitle'))
                <p class="lead hero__lead">{{ $cfg->get('hero_subtitle') }}</p>
            @endif
            <div class="hero__actions">
                <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Agendar horário</x-ui.button>
                <a class="link-arrow" href="{{ route('site.services') }}">Ver serviços e preços <x-icon name="arrow-right" /></a>
            </div>
        </div>
    </section>
    <div class="barber-stripe" aria-hidden="true"></div>

    {{-- INFORMACOES PRATICAS: so o que existe (horario da agenda, endereco, nota real). --}}
    @if ($status || $cfg->has('address') || $rating)
        <section class="info-strip" aria-label="Informações rápidas">
            <div class="container info-strip__list">
                @if ($status)
                    <p class="info-strip__item"><x-icon name="clock" /><span><strong data-open-status @class(['is-open' => $status['open']])>{{ $status['text'] }}</strong><a href="#local">Ver todos os horários</a></span></p>
                @endif
                @if ($cfg->has('address'))
                    <p class="info-strip__item"><x-icon name="map-pin" /><span><strong>{{ $cfg->get('address') }}</strong>@if ($cfg->mapsUrl())<a href="{{ $cfg->mapsUrl() }}" rel="noopener" target="_blank">Abrir no mapa<span class="visually-hidden"> (abre em outra aba)</span></a>@endif</span></p>
                @endif
                @if ($rating)
                    <p class="info-strip__item"><x-icon name="star" /><span><strong data-rating>Nota {{ number_format($rating['average'], 1, ',', '') }} de 5</strong>{{ $rating['count'] }} avaliações de clientes</span></p>
                @endif
            </div>
        </section>
    @endif

    {{-- SERVICOS: o mesmo catalogo do painel (destaques), com preco e duracao. --}}
    <section class="section" id="servicos" aria-labelledby="servicos-titulo">
        <div class="container">
            <header class="section__head section__head--split">
                <div class="stack stack-sm">
                    <p class="eyebrow">Serviços</p>
                    <h2 id="servicos-titulo" class="h2">Preço e tempo, antes de você decidir.</h2>
                </div>
                <a class="link-arrow" href="{{ route('site.services') }}">Todos os serviços <x-icon name="arrow-right" /></a>
            </header>
            @if ($services->isEmpty())
                <p class="text-muted">Os serviços aparecem aqui assim que forem publicados.</p>
            @else
                <ul class="menu-board" role="list">
                    @foreach ($services as $s)
                        <li class="menu-line" data-service="{{ $s->slug }}">
                            <a class="menu-line__link" href="{{ route('booking.professional', $s) }}">
                                <span class="menu-line__top">
                                    <span class="menu-line__name">{{ $s->name }}</span>
                                    <span class="menu-line__dots" aria-hidden="true"></span>
                                    <span class="menu-line__price">{{ $s->price()->format() }}</span>
                                </span>
                                <span class="menu-line__meta">
                                    <span><x-icon name="clock" /> {{ $s->durationLabel() }}</span>
                                    @if ($s->category)<span>{{ $s->category->name }}</span>@endif
                                    <span class="menu-line__cta">Agendar<span class="visually-hidden"> {{ $s->name }}</span> <x-icon name="arrow-right" /></span>
                                </span>
                                @if ($s->description)<span class="menu-line__desc">{{ $s->description }}</span>@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- ASSINATURA (D-03): planos ativos reais. --}}
    @if ($plans->isNotEmpty())
        <section class="section section--band" id="assinatura" aria-labelledby="assinatura-titulo">
            <div class="container split split--top">
                <div class="stack">
                    <p class="eyebrow">Assinatura</p>
                    <h2 id="assinatura-titulo" class="h2">Cliente de casa, todo mês.</h2>
                    <p class="text-muted">Os serviços do plano saem sem custo no agendamento enquanto a assinatura estiver em dia.</p>
                    <a class="link-arrow" href="{{ route('site.plans') }}">Conhecer os planos <x-icon name="arrow-right" /></a>
                </div>
                <ul class="plan-list" role="list">
                    @foreach ($plans as $p)
                        <li class="plan-card">
                            <h3 class="plan-card__name">{{ $p->name }}</h3>
                            <p class="plan-card__price">{{ $p->currentVersion?->priceLabel() }}</p>
                            @if ($p->currentVersion && $p->currentVersion->services->isNotEmpty())
                                <p class="plan-card__items">Inclui: {{ $p->currentVersion->services->pluck('name')->join(', ') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- EQUIPE: so profissionais ativos, que recebem agendamento e estao no site. --}}
    @if ($team->isNotEmpty())
        <section class="section" id="equipe" aria-labelledby="equipe-titulo">
            <div class="container">
                <header class="section__head section__head--split">
                    <div class="stack stack-sm">
                        <p class="eyebrow">Equipe</p>
                        <h2 id="equipe-titulo" class="h2">Escolha quem vai cuidar de você.</h2>
                    </div>
                    <a class="link-arrow" href="{{ route('site.team') }}">Conhecer a equipe <x-icon name="arrow-right" /></a>
                </header>
                <ul class="team" role="list">
                    @foreach ($team as $pro)
                        <li>
                            @include('site.partials.pro-card', ['pro' => $pro])
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- A BARBEARIA: texto e diferenciais do dono + foto do ambiente (se houver). --}}
    @if ($cfg->has('about_text') || $about->isNotEmpty() || $cfg->highlights() !== [])
        <section class="section section--paper" id="sobre" aria-labelledby="sobre-titulo" data-superficie="clara">
            <div class="container split">
                <div class="stack stack-lg">
                    <div class="stack">
                        <p class="eyebrow">A barbearia</p>
                        <h2 id="sobre-titulo" class="h2">{{ $cfg->has('about_title') ? $cfg->get('about_title') : 'Um lugar para sentar, conversar e sair bem.' }}</h2>
                        @foreach ($cfg->blocks('about_text') as $b)
                            <p @class(['lead' => $loop->first, 'pre-line'])>{{ $b['text'] }}</p>
                        @endforeach
                    </div>
                    @if ($cfg->highlights() !== [])
                        <ul class="values" role="list">
                            @foreach ($cfg->highlights() as $h)
                                <li class="values__item"><x-icon name="badge-check" /><div><h3>{{ $h['title'] }}</h3>@if ($h['text'] !== '')<p>{{ $h['text'] }}</p>@endif</div></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if ($about->isNotEmpty())
                    <div class="about-photos">
                        @foreach ($about as $img)
                            <figure class="about-photos__item">
                                <x-site.img :path="$img->path" :alt="$img->alt" sizes="(min-width: 64rem) 40vw, 100vw" />
                                @if ($img->caption)<figcaption>{{ $img->caption }}</figcaption>@endif
                            </figure>
                        @endforeach
                    </div>
                @else
                    <div class="about-mark" aria-hidden="true"><x-site.ornament name="comb" /></div>
                @endif
            </div>
        </section>
    @endif

    {{-- GALERIA: trabalhos reais (a partir de 3 fotos). --}}
    @if ($gallery->isNotEmpty())
        <section class="section" id="galeria" aria-labelledby="galeria-titulo">
            <div class="container">
                <header class="section__head">
                    <p class="eyebrow">Galeria</p>
                    <h2 id="galeria-titulo" class="h2">Trabalhos da casa.</h2>
                </header>
                <ul class="photo-grid" role="list">
                    @foreach ($gallery as $img)
                        <li>
                            <figure>
                                <x-site.img :path="$img->path" :alt="$img->alt" sizes="(min-width: 64rem) 25vw, 50vw" />
                                @if ($img->caption)<figcaption>{{ $img->caption }}</figcaption>@endif
                            </figure>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- AVALIACOES: so as reais, aprovadas e destacadas pela equipe (texto, nunca HTML). --}}
    @if ($reviews->isNotEmpty())
        <section class="section section--band" id="avaliacoes" aria-labelledby="avaliacoes-titulo">
            <div class="container">
                <header class="section__head">
                    <p class="eyebrow">Avaliações</p>
                    <h2 id="avaliacoes-titulo" class="h2">O que dizem depois do espelho.</h2>
                </header>
                <div class="quotes">
                    @foreach ($reviews as $r)
                        <figure class="quote" data-review>
                            <blockquote><p class="pre-line">“{{ $r->comment }}”</p></blockquote>
                            <figcaption>
                                <span class="stars" role="img" aria-label="{{ $r->rating }} de 5 estrelas">@for ($i = 0; $i < $r->rating; $i++)<x-icon name="star" />@endfor</span>
                                <span>{{ PublicSite::reviewerName($r) }}@if ($r->professional) · com {{ $r->professional->display_name }}@endif</span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- COMO CHEGAR E HORARIOS: endereco do dono, horario da agenda. --}}
    @php $temContato = $cfg->has('address') || $cfg->whatsappUrl() || $cfg->phoneHref(); @endphp
    <section class="section" id="local" aria-labelledby="local-titulo">
        <div @class(['container', 'split split--top' => $temContato, 'stack stack-lg' => ! $temContato])>
            <div class="stack stack-lg">
                <div class="stack">
                    <p class="eyebrow">Como chegar</p>
                    <h2 id="local-titulo" class="h2">{{ $cfg->has('address') ? $cfg->get('address') : 'Horários e contato' }}</h2>
                    @if ($cfg->has('address_note'))<p class="text-muted">{{ $cfg->get('address_note') }}</p>@endif
                </div>
                <div class="cluster">
                    @if ($cfg->mapsUrl())
                        <x-ui.button :href="$cfg->mapsUrl()" variant="secondary" icon="map-pin" rel="noopener" target="_blank">Abrir no mapa<span class="visually-hidden"> (abre em outra aba)</span></x-ui.button>
                    @endif
                    @if ($cfg->whatsappUrl())
                        <x-ui.button :href="$cfg->whatsappUrl()" variant="secondary" icon="message-circle" rel="noopener" target="_blank">WhatsApp<span class="visually-hidden"> (abre em outra aba)</span></x-ui.button>
                    @endif
                    @if ($cfg->phoneHref())
                        <x-ui.button :href="$cfg->phoneHref()" variant="secondary" icon="phone">Ligar</x-ui.button>
                    @endif
                </div>
            </div>
            @if ($hours->isConfigured())
                <div class="stack stack-sm">
                    <h3 class="eyebrow">Horário de funcionamento</h3>
                    @if ($status)<p class="open-status" data-open-status @class(['is-open' => $status['open']])><span class="open-status__dot" aria-hidden="true"></span>{{ $status['text'] }}</p>@endif
                    <dl class="hours">
                        @foreach ($hours->table() as $h)
                            <div @class(['is-today' => $h['today']])>
                                <dt>{{ $h['day'] }}@if ($h['today']) <span class="hours__today">hoje</span>@endif</dt>
                                <dd>{{ $h['hours'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </section>

    {{-- CHAMADA FINAL --}}
    <section class="section section--cta" aria-labelledby="cta-titulo">
        <div class="container cta-final">
            <x-site.ornament name="scissors" class="cta-final__ornament" />
            <h2 id="cta-titulo" class="h1">Sua cadeira está livre?</h2>
            <p class="lead">Veja os horários disponíveis antes de criar conta.</p>
            <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Ver horários</x-ui.button>
        </div>
    </section>
</x-site.page>

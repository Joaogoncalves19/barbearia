{{--
    INICIO do site publico (redesign "Oficio"; home.md). A pagina conta uma
    historia curta: onde estou e que casa e esta (topo) -> o que tem e quanto
    custa (servicos) -> quem atende (equipe) -> como e a casa -> o que dizem
    -> assinatura -> como chegar -> agendar. Cada bloco so aparece com
    conteudo REAL; a numeracao 01, 02... e feita pelo CSS (nunca pula numero).

    Sem foto do topo, o lado direito vira o "letreiro" da casa: os servicos e
    precos reais, como o quadro na parede da barbearia (nada inventado).
--}}
@php
    use App\Modules\SiteContent\Services\PublicSite;
    $agendar = route('booking.services');
    $nomeCurto = $cfg->name();
    $letreiro = $services->take(5);
@endphp
<x-site.page :json-ld="$jsonLd" :canonical="route('home')">
    {{-- TOPO ------------------------------------------------------------------------- --}}
    <section @class(['opening', 'opening--photo' => $hero]) aria-labelledby="inicio-titulo">
        <div class="container opening__grid">
            <div class="opening__text">
                <p class="eyebrow">Barbearia{{ $cfg->has('neighborhood') ? ' · '.$cfg->get('neighborhood') : '' }}</p>
                <h1 id="inicio-titulo" class="display caps opening__title">{{ $cfg->has('tagline') ? $cfg->get('tagline') : $nomeCurto }}</h1>
                @if ($cfg->has('tagline'))
                    <p class="opening__name">{{ $nomeCurto }}</p>
                @endif
                @if ($cfg->has('hero_subtitle'))
                    <p class="lead opening__lead">{{ $cfg->get('hero_subtitle') }}</p>
                @endif
                <div class="opening__actions">
                    <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Agendar horário</x-ui.button>
                    <a class="link-arrow" href="{{ route('site.services') }}">Ver serviços e preços <x-icon name="arrow-right" /></a>
                </div>
            </div>

            @if ($hero)
                <figure class="opening__photo">
                    <x-site.img :path="$hero->path" :alt="$hero->alt" sizes="(min-width: 64rem) 42vw, 100vw" eager />
                </figure>
            @elseif ($letreiro->isNotEmpty())
                <aside class="signboard" aria-label="Alguns serviços e preços">
                    <p class="signboard__head"><span>Na cadeira</span><span>R$</span></p>
                    <ul class="signboard__list" role="list">
                        @foreach ($letreiro as $s)
                            <li>
                                <span class="signboard__name">{{ $s->name }}</span>
                                <span class="signboard__dots" aria-hidden="true"></span>
                                <span class="signboard__price figure">{{ $s->price()->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="signboard__foot"><span class="ruler ruler--accent" aria-hidden="true"></span></p>
                </aside>
            @endif
        </div>

        {{-- Ficha rapida: so o que existe (horario da agenda, endereco, nota real). --}}
        @if ($status || $cfg->has('address') || $rating)
            <div class="container">
                <dl class="facts" aria-label="Informações rápidas">
                    @if ($status)
                        <div class="facts__item">
                            <dt>Hoje</dt>
                            <dd><strong data-open-status @class(['is-open' => $status['open']])>{{ $status['text'] }}</strong> <a href="#local">Todos os horários</a></dd>
                        </div>
                    @endif
                    @if ($cfg->has('address'))
                        <div class="facts__item">
                            <dt>Endereço</dt>
                            <dd><strong>{{ $cfg->get('address') }}</strong>@if ($cfg->mapsUrl()) <a href="{{ $cfg->mapsUrl() }}" rel="noopener" target="_blank">Abrir no mapa<span class="visually-hidden"> (abre em outra aba)</span></a>@endif</dd>
                        </div>
                    @endif
                    @if ($rating)
                        <div class="facts__item">
                            <dt>Avaliação</dt>
                            <dd><strong data-rating>Nota {{ number_format($rating['average'], 1, ',', '') }} de 5</strong> {{ $rating['count'] }} avaliações de clientes</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif
    </section>

    {{-- SERVICOS: o mesmo catalogo do painel (destaques), com preco e duracao. ---------- --}}
    <section class="chapter" id="servicos" aria-labelledby="servicos-titulo">
        <div class="container chapter__grid">
            <header class="chapter__head">
                <p class="chapter__index" aria-hidden="true"></p>
                <h2 id="servicos-titulo" class="h2 caps">Serviços</h2>
                <p class="text-muted">Preço e tempo, antes de você decidir. O valor que aparece aqui é o que fica no seu agendamento.</p>
                <a class="link-arrow" href="{{ route('site.services') }}">Todos os serviços <x-icon name="arrow-right" /></a>
            </header>
            @if ($services->isEmpty())
                <x-ui.empty-state title="Serviços em breve" icon="scissors">Os serviços aparecem aqui assim que forem publicados pela barbearia.</x-ui.empty-state>
            @else
                <ul class="service-list" role="list">
                    @foreach ($services as $s)
                        <li class="service-row" data-service="{{ $s->slug }}">
                            <a href="{{ route('booking.professional', $s) }}">
                                <span class="service-row__name">{{ $s->name }}</span>
                                <span class="service-row__price figure">{{ $s->price()->format() }}</span>
                                <span class="service-row__meta"><x-icon name="clock" class="icon-sm" /> {{ $s->durationLabel() }}@if ($s->category) · {{ $s->category->name }}@endif</span>
                                @if ($s->description)<span class="service-row__desc">{{ $s->description }}</span>@endif
                                <span class="service-row__cta">Agendar<span class="visually-hidden"> {{ $s->name }}</span> <x-icon name="arrow-right" /></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- EQUIPE: so profissionais ativos, que recebem agendamento e estao no site. ------- --}}
    @if ($team->isNotEmpty())
        <section class="chapter chapter--band" id="equipe" aria-labelledby="equipe-titulo">
            <div class="container">
                <header class="chapter__head chapter__head--row">
                    <div class="stack stack-sm">
                        <p class="chapter__index" aria-hidden="true"></p>
                        <h2 id="equipe-titulo" class="h2 caps">Equipe</h2>
                        <p class="text-muted">Escolha quem vai cuidar de você.</p>
                    </div>
                    <a class="link-arrow" href="{{ route('site.team') }}">Conhecer a equipe <x-icon name="arrow-right" /></a>
                </header>
                <ul class="team" role="list">
                    @foreach ($team as $pro)
                        <li>@include('site.partials.pro-card', ['pro' => $pro])</li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- A CASA: texto e diferenciais do dono + fotos do ambiente (se houver). ------------ --}}
    @if ($cfg->has('about_text') || $about->isNotEmpty() || $cfg->highlights() !== [])
        <section class="chapter chapter--paper" id="sobre" aria-labelledby="sobre-titulo" data-superficie="clara">
            <div class="container chapter__grid">
                <header class="chapter__head">
                    <p class="chapter__index" aria-hidden="true"></p>
                    <h2 id="sobre-titulo" class="h2 caps">{{ $cfg->has('about_title') ? $cfg->get('about_title') : 'A barbearia' }}</h2>
                </header>
                <div class="stack stack-xl">
                    <div class="stack">
                        @foreach ($cfg->blocks('about_text') as $b)
                            <p @class(['lead about__lead' => $loop->first, 'pre-line'])>{{ $b['text'] }}</p>
                        @endforeach
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
                    @endif
                    @if ($cfg->highlights() !== [])
                        <ol class="values" role="list">
                            @foreach ($cfg->highlights() as $h)
                                <li class="values__item">
                                    <span class="values__n figure" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div><h3 class="values__title">{{ $h['title'] }}</h3>@if ($h['text'] !== '')<p>{{ $h['text'] }}</p>@endif</div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- GALERIA: trabalhos reais (a partir de 3 fotos). --------------------------------- --}}
    @if ($gallery->isNotEmpty())
        <section class="chapter" id="galeria" aria-labelledby="galeria-titulo">
            <div class="container">
                <header class="chapter__head chapter__head--row">
                    <div class="stack stack-sm">
                        <p class="chapter__index" aria-hidden="true"></p>
                        <h2 id="galeria-titulo" class="h2 caps">Trabalhos da casa</h2>
                    </div>
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

    {{-- AVALIACOES: so as reais, aprovadas e destacadas pela equipe (texto, nunca HTML). - --}}
    @if ($reviews->isNotEmpty())
        <section class="chapter" id="avaliacoes" aria-labelledby="avaliacoes-titulo">
            <div class="container chapter__grid">
                <header class="chapter__head">
                    <p class="chapter__index" aria-hidden="true"></p>
                    <h2 id="avaliacoes-titulo" class="h2 caps">O que dizem depois do espelho</h2>
                    @if ($rating)
                        <p class="score"><span class="figure figure--lg">{{ number_format($rating['average'], 1, ',', '') }}</span> <span class="text-muted">de 5 · {{ $rating['count'] }} avaliações</span></p>
                    @endif
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

    {{-- ASSINATURA (D-03): planos ativos reais. ---------------------------------------- --}}
    @if ($plans->isNotEmpty())
        <section class="chapter chapter--band" id="assinatura" aria-labelledby="assinatura-titulo">
            <div class="container chapter__grid">
                <header class="chapter__head">
                    <p class="chapter__index" aria-hidden="true"></p>
                    <h2 id="assinatura-titulo" class="h2 caps">Assinatura</h2>
                    <p class="text-muted">Cliente de casa, todo mês: os serviços do plano saem sem custo no agendamento enquanto a assinatura estiver em dia.</p>
                    <a class="link-arrow" href="{{ route('site.plans') }}">Conhecer os planos <x-icon name="arrow-right" /></a>
                </header>
                <ul class="plan-list" role="list">
                    @foreach ($plans as $p)
                        <li class="plan-card">
                            <h3 class="plan-card__name">{{ $p->name }}</h3>
                            <p class="plan-card__price figure">{{ $p->currentVersion?->priceLabel() }}</p>
                            @if ($p->currentVersion && $p->currentVersion->services->isNotEmpty())
                                <p class="plan-card__items">Inclui: {{ $p->currentVersion->services->pluck('name')->join(', ') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- COMO CHEGAR E HORARIOS: endereco do dono, horario da agenda. -------------------- --}}
    <section class="chapter" id="local" aria-labelledby="local-titulo">
        <div class="container chapter__grid">
            <header class="chapter__head">
                <p class="chapter__index" aria-hidden="true"></p>
                <h2 id="local-titulo" class="h2 caps">{{ $cfg->has('address') ? 'Visite' : 'Horários e contato' }}</h2>
            </header>
            <div class="visit">
                <div class="stack">
                    @if ($cfg->has('address'))
                        <p class="visit__address">{{ $cfg->get('address') }}</p>
                    @endif
                    @if ($cfg->has('address_note'))<p class="text-muted">{{ $cfg->get('address_note') }}</p>@endif
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
                        <h3 class="eyebrow eyebrow--plain">Horário de funcionamento</h3>
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
        </div>
    </section>

    {{-- CHAMADA FINAL ------------------------------------------------------------------ --}}
    <section class="closing" aria-labelledby="cta-titulo">
        <div class="container closing__inner">
            <span class="ruler ruler--accent closing__ruler" aria-hidden="true"></span>
            <h2 id="cta-titulo" class="display caps closing__title">Sua cadeira está livre?</h2>
            <p class="lead">Veja os horários disponíveis antes de criar conta.</p>
            <x-ui.button :href="$agendar" variant="accent" size="lg" icon="calendar">Ver horários</x-ui.button>
        </div>
    </section>
</x-site.page>

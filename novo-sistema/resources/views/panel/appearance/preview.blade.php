{{--
    Previa de um tema (temas-visuais.md). A pagina inteira (barra lateral,
    topo, componentes) ja esta no tema escolhido SO nesta requisicao; nada e
    gravado. Mostra as pecas que mais dependem do tema: botoes, numeros,
    agenda, tabela, formulario, alertas, estado vazio e um pedaco do site com
    o nome e os servicos reais da barbearia. Os horarios da agenda e da tabela
    sao EXEMPLOS (marcados como tal), nao dados da barbearia.
--}}
@php
    $site = app(\App\Modules\SiteContent\Services\PublicSite::class);
    $cfg = $site->settings();
    $nome = $cfg->name();
    $servicos = $site->featuredServices()->take(3);
@endphp
<x-layouts.staff :title="'Prévia: '.$theme->name()">
    {{-- O pedaco do site usa os mesmos estilos do site publico. --}}
    @push('head')
        @vite('resources/css/site.css')
    @endpush
    <x-ui.alert variant="info" :title="'Prévia do tema '.$theme->name()" class="theme-preview-banner">
        Nada mudou ainda: o site, a conta dos clientes e o painel continuam no tema {{ $current->name() }} até você confirmar.
        <div class="cluster theme-preview-banner__actions">
            @if ($theme->is($current))
                <x-ui.badge variant="success">Este já é o tema em uso</x-ui.badge>
            @else
                <form method="POST" action="{{ route('panel.appearance.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="theme" value="{{ $theme->key }}">
                    <x-ui.button type="submit" variant="accent" size="sm" icon="check">Usar este tema</x-ui.button>
                </form>
            @endif
            <x-ui.button :href="route('panel.appearance')" variant="secondary" size="sm" icon="arrow-left">Voltar para os temas</x-ui.button>
        </div>
    </x-ui.alert>

    <header class="page-head">
        <div class="stack stack-sm">
            <p class="eyebrow">Aparência · prévia</p>
            <h1 class="page-head__title">{{ $theme->name() }}</h1>
            <p class="text-muted">{{ $theme->description() }}</p>
        </div>
        <div class="cluster">
            <x-ui.button variant="secondary" icon="plus">Secundário</x-ui.button>
            <x-ui.button variant="primary" icon="calendar-plus">Principal</x-ui.button>
        </div>
    </header>

    {{-- O SITE ------------------------------------------------------------------------ --}}
    <section class="theme-preview-site" data-superficie="{{ $theme->surface('site') }}" aria-labelledby="previa-site">
        <div class="opening theme-preview-site__opening">
            <div class="theme-preview-site__grid">
                <div class="opening__text">
                    <p class="eyebrow">Barbearia{{ $cfg->has('neighborhood') ? ' · '.$cfg->get('neighborhood') : '' }}</p>
                    <h2 id="previa-site" class="h1 caps">{{ $cfg->has('tagline') ? $cfg->get('tagline') : $nome }}</h2>
                    @if ($cfg->has('tagline'))<p class="opening__name">{{ $nome }}</p>@endif
                    <div class="opening__actions">
                        <span class="btn btn--accent" aria-hidden="true"><x-icon name="calendar" /><span>Agendar horário</span></span>
                        <span class="link-arrow" aria-hidden="true">Ver serviços e preços <x-icon name="arrow-right" /></span>
                    </div>
                </div>
                @if ($servicos->isNotEmpty())
                    <div class="signboard" aria-label="Serviços do site">
                        <p class="signboard__head"><span>Na cadeira</span><span>R$</span></p>
                        <ul class="signboard__list" role="list">
                            @foreach ($servicos as $s)
                                <li>
                                    <span class="signboard__name">{{ $s->name }}</span>
                                    <span class="signboard__dots" aria-hidden="true"></span>
                                    <span class="signboard__price figure">{{ $s->price()->format() }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="signboard__foot"><span class="ruler ruler--accent" aria-hidden="true"></span></p>
                    </div>
                @endif
            </div>
        </div>
        <div class="theme-preview-site__chapter">
            <span class="chapter__index" aria-hidden="true"></span>
            <p class="h3 caps">Quem cuida de você</p>
        </div>
    </section>

    <div class="dashboard-grid theme-preview-grid">
        {{-- NUMEROS --------------------------------------------------------------- --}}
        <section class="board" aria-labelledby="previa-numeros">
            <div class="board__head"><h2 id="previa-numeros" class="title">Números <x-ui.badge variant="sample">Exemplo</x-ui.badge></h2></div>
            <dl class="stats">
                <div class="stat stat--key"><dt class="stat__label">Na gaveta</dt><dd class="stat__value figure"><span class="figure__unit">R$</span>480,00</dd></div>
                <div class="stat"><dt class="stat__label">Atendimentos</dt><dd class="stat__value figure">12</dd></div>
                <div class="stat"><dt class="stat__label">A seguir</dt><dd class="stat__value figure">4</dd></div>
            </dl>
        </section>

        {{-- AGENDA ---------------------------------------------------------------- --}}
        <section class="board" aria-labelledby="previa-agenda">
            <div class="board__head"><h2 id="previa-agenda" class="title">Agenda <x-ui.badge variant="sample">Exemplo</x-ui.badge></h2></div>
            <ul class="theme-preview-agenda" role="list">
                <li class="ev ev--in-progress"><span class="ev__inner"><span class="ev__time numeric">09:00–09:45</span><strong class="ev__who">Cliente A</strong><span class="ev__what">Corte</span><span class="ev__state">Em atendimento</span></span></li>
                <li class="ev"><span class="ev__inner"><span class="ev__time numeric">10:00–10:30</span><strong class="ev__who">Cliente B</strong><span class="ev__what">Barba</span><span class="ev__state">Confirmado</span></span></li>
                <li class="ev ev--pending"><span class="ev__inner"><span class="ev__time numeric">10:30–11:00</span><strong class="ev__who">Cliente C</strong><span class="ev__what">Corte</span><span class="ev__state">A confirmar</span></span></li>
                <li class="ev ev--completed"><span class="ev__inner"><span class="ev__time numeric">08:00–08:45</span><strong class="ev__who">Cliente D</strong><span class="ev__what">Corte e barba</span><span class="ev__state">Concluído</span></span></li>
                <li class="ev is-conflict"><span class="ev__inner"><span class="ev__time numeric">11:00–11:30</span><strong class="ev__who">Cliente E</strong><span class="ev__what">Corte</span><span class="ev__state"><x-icon name="triangle-alert" class="icon-sm" /> Conflito</span></span></li>
                <li class="ev ev--block"><span class="ev__time numeric">12:00–13:00</span><strong class="ev__who">Bloqueado</strong><span class="ev__what">Almoço</span></li>
            </ul>
        </section>
    </div>

    {{-- TABELA ---------------------------------------------------------------------- --}}
    <section class="board" aria-labelledby="previa-tabela">
        <div class="board__head"><h2 id="previa-tabela" class="title">Tabela <x-ui.badge variant="sample">Exemplo</x-ui.badge></h2></div>
        <x-ui.table caption="Tabela de exemplo" caption-hidden stacked>
            <thead><tr><th scope="col">Serviço</th><th scope="col">Duração</th><th scope="col">Situação</th><th scope="col" class="numeric">Preço</th></tr></thead>
            <tbody>
                <tr><td data-label="Serviço">Serviço 1</td><td data-label="Duração">30 min</td><td data-label="Situação"><x-ui.badge variant="success">Ativo</x-ui.badge></td><td data-label="Preço" class="numeric cell-figure">R$ 50,00</td></tr>
                <tr><td data-label="Serviço">Serviço 2</td><td data-label="Duração">45 min</td><td data-label="Situação"><x-ui.badge variant="warning">Fora do site</x-ui.badge></td><td data-label="Preço" class="numeric cell-figure">R$ 70,00</td></tr>
                <tr><td data-label="Serviço">Serviço 3</td><td data-label="Duração">60 min</td><td data-label="Situação"><x-ui.badge>Inativo</x-ui.badge></td><td data-label="Preço" class="numeric cell-figure">R$ 90,00</td></tr>
            </tbody>
        </x-ui.table>
    </section>

    <div class="dashboard-grid theme-preview-grid">
        {{-- FORMULARIO ------------------------------------------------------------ --}}
        <section class="board" aria-labelledby="previa-form">
            <div class="board__head"><h2 id="previa-form" class="title">Formulário <x-ui.badge variant="sample">Exemplo</x-ui.badge></h2></div>
            <div class="stack">
                <x-ui.input name="previa_nome" label="Nome" value="Cliente A" />
                <x-ui.input name="previa_tel" label="Telefone" hint="Com DDD." optional />
                <x-ui.input name="previa_erro" label="E-mail" value="cliente@" :error="'Informe um e-mail válido.'" />
                <x-ui.checkbox name="previa_ok" label="Aceita receber lembretes" checked />
                <div class="cluster">
                    <x-ui.button variant="accent" icon="check">Concluir e receber</x-ui.button>
                    <x-ui.button variant="ghost">Cancelar</x-ui.button>
                    <x-ui.button variant="danger" icon="trash-2">Excluir</x-ui.button>
                </div>
            </div>
        </section>

        {{-- MENSAGENS ------------------------------------------------------------- --}}
        <section class="board" aria-labelledby="previa-avisos">
            <div class="board__head"><h2 id="previa-avisos" class="title">Mensagens e estados</h2></div>
            <div class="stack">
                <x-ui.alert variant="success">Atendimento concluído e pago.</x-ui.alert>
                <x-ui.alert variant="warning">O caixa ainda não foi aberto hoje.</x-ui.alert>
                <x-ui.alert variant="danger">Não foi possível salvar. Confira os campos.</x-ui.alert>
                <div class="cluster">
                    <x-ui.badge variant="success">Pago</x-ui.badge>
                    <x-ui.badge variant="warning">A confirmar</x-ui.badge>
                    <x-ui.badge variant="danger">Não veio</x-ui.badge>
                    <x-ui.badge variant="info">Encaixe</x-ui.badge>
                    <x-ui.badge variant="accent">Destaque</x-ui.badge>
                </div>
                <x-ui.empty-state title="Nada por aqui ainda" icon="calendar">Quando houver horários, eles aparecem nesta lista.</x-ui.empty-state>
            </div>
        </section>
    </div>
</x-layouts.staff>

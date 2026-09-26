{{--
    CATALOGO DO DESIGN SYSTEM — tokens e componentes com todos os estados.
    Serve de referencia viva para quem constroi telas nas proximas fases.
--}}
@php
    $primitivos = [
        'Tinta / carvão' => ['ink-950', 'ink-900', 'ink-800', 'ink-700', 'ink-600'],
        'Pedra (neutros)' => ['stone-600', 'stone-500', 'stone-300'],
        'Papel / gesso' => ['paper-200', 'paper-100', 'paper-50', 'paper-0'],
        'Acento' => ['accent-700', 'accent-600', 'accent-500', 'accent-300', 'accent-100'],
        'Estados' => ['success-700', 'warning-700', 'danger-700', 'info-700'],
    ];
    $tipos = [
        ['display', 'Display — hero', 'Corte e barba'],
        ['h1', 'H1 — título de página', 'Serviços e preços'],
        ['h2', 'H2 — título de seção', 'Escolha quem vai cuidar de você'],
        ['h3', 'H3 — subtítulo', 'Barba com toalha quente'],
        ['title', 'Título do painel (fonte de texto)', 'Agenda de hoje'],
        ['lead', 'Lead — texto de apoio', 'Profissionais que ouvem antes de cortar.'],
        ['', 'Corpo (16px)', 'Texto corrido para descrições e explicações, com altura de linha confortável.'],
        ['text-sm', 'Pequeno (14px) — interface do painel', 'Cliente confirmou presença às 09:12.'],
        ['eyebrow', 'Sobrelinha', 'Agendamento online'],
    ];
    $espacos = [1, 2, 3, 4, 6, 8, 12, 16, 24];
    $icones = ['calendar', 'clock', 'scissors', 'user', 'users', 'map-pin', 'phone', 'message-circle', 'wallet', 'chart-column', 'package', 'star', 'bell', 'search', 'settings', 'check', 'circle-check', 'triangle-alert', 'info', 'circle-x', 'plus', 'pencil', 'trash-2', 'log-out'];
@endphp
<x-layouts.document title="Design System" :direction="$direcao" surface="clara" area="panel" :noindex="true" body-class="ds">
    @push('head')
        <style nonce="{{ Vite::cspNonce() }}">
            @foreach ($primitivos as $grupo)
                @foreach ($grupo as $token)
                    .sw-{{ $token }} { background: var(--p-{{ $token }}); }
                @endforeach
            @endforeach
            @foreach ($espacos as $e)
                .sp-{{ $e }} { width: var(--space-{{ $e }}); }
            @endforeach
            /* Estilos exclusivos desta pagina de catalogo (nao vao para o bundle). */
            .ds-main { padding-block: var(--space-10) var(--space-24); }
            .ds-main > section { padding-top: var(--space-6); border-top: var(--border-width) solid var(--c-border); }
            .ds-grid { display: grid; gap: var(--space-4); grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); align-items: start; }
            .ds-swatches { display: grid; gap: var(--space-3); grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); }
            .ds-swatch { display: grid; gap: var(--space-1); font-size: var(--fs-xs); }
            .ds-swatch__color { height: 3.5rem; border-radius: var(--radius-control); border: var(--border-width) solid var(--c-border); }
            .ds-type { display: grid; gap: var(--space-1); padding-block: var(--space-3); border-bottom: var(--border-width) solid var(--c-border); }
            .ds-space { display: grid; grid-template-columns: 7rem 1fr; align-items: center; gap: var(--space-3); font-size: var(--fs-xs); }
            .ds-space__bar { height: 0.75rem; background: var(--c-accent); border-radius: 2px; }
            .ds-shapes { display: flex; flex-wrap: wrap; gap: var(--space-4); }
            .ds-shape { display: grid; place-items: center; min-width: 7rem; min-height: 4rem; padding: var(--space-2); border: var(--border-width) solid var(--c-border); background: var(--c-surface); font-size: var(--fs-xs); text-align: center; }
            .ds-shape--control { border-radius: var(--radius-control); }
            .ds-shape--card { border-radius: var(--radius-card); }
            .ds-shape--pill { border-radius: var(--radius-pill); }
            .ds-shape--shadow-md { border-radius: var(--radius-control); box-shadow: var(--shadow-md); border-color: transparent; }
            .ds-shape--shadow-lg { border-radius: var(--radius-card); box-shadow: var(--shadow-lg); border-color: transparent; }
            .ds-icons { display: grid; gap: var(--space-3); grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); }
            .ds-icons li { display: grid; justify-items: center; gap: var(--space-2); padding: var(--space-3); border: var(--border-width) solid var(--c-border); border-radius: var(--radius-control); font-size: var(--fs-xs); }
            .ds-nav { display: grid; gap: var(--space-1); max-width: 16rem; }
            .ds-surface { display: grid; gap: var(--space-4); padding: var(--space-6); border-radius: var(--radius-card); background: var(--c-bg); color: var(--c-text); border: var(--border-width) solid var(--c-border); }
        </style>
    @endpush

    @include('partials.prototype-banner', ['direcao' => $direcao])

    <main id="conteudo" class="ds-main container stack stack-lg">
        <header class="stack">
            <p class="eyebrow">Design System · Fase 1</p>
            <h1 class="h1">Componentes e tokens</h1>
            <p class="lead">Referência viva. Use estes componentes (<code>resources/views/components/ui</code>) em vez de criar variações. Documentação em <code>docs/reconstrucao/design-system.md</code>.</p>
            <nav class="cluster" aria-label="Seções">
                @foreach (['cores' => 'Cores', 'tipografia' => 'Tipografia', 'espaco' => 'Espaço e forma', 'icones' => 'Ícones', 'botoes' => 'Botões', 'formularios' => 'Formulários', 'conteudo-ds' => 'Conteúdo', 'feedback' => 'Feedback', 'navegacao' => 'Navegação', 'dados' => 'Dados', 'sobreposicoes' => 'Modais e menus', 'superficies' => 'Superfícies'] as $ancora => $rotulo)
                    <a href="#{{ $ancora }}">{{ $rotulo }}</a>
                @endforeach
            </nav>
        </header>

        {{-- CORES --}}
        <section id="cores" class="stack" aria-labelledby="t-cores">
            <h2 id="t-cores" class="h2">Cores — direção {{ strtoupper($direcao) }}</h2>
            <p class="text-muted">Primitivos da paleta. Componentes usam só os tokens semânticos (<code>--c-*</code>).</p>
            @foreach ($primitivos as $nome => $tokens)
                <div class="stack stack-sm">
                    <h3 class="title">{{ $nome }}</h3>
                    <div class="ds-swatches">
                        @foreach ($tokens as $token)
                            <figure class="ds-swatch"><span class="ds-swatch__color sw-{{ $token }}"></span><figcaption><code>--p-{{ $token }}</code></figcaption></figure>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        {{-- TIPOGRAFIA --}}
        <section id="tipografia" class="stack" aria-labelledby="t-tipo">
            <h2 id="t-tipo" class="h2">Tipografia</h2>
            <div class="card">
                @foreach ($tipos as [$classe, $rotulo, $exemplo])
                    <div class="ds-type">
                        <span class="text-xs text-muted">{{ $rotulo }}</span>
                        <p class="{{ $classe }}">{{ $exemplo }}</p>
                    </div>
                @endforeach
                <p class="text-sm">Números tabulares: <span class="numeric">R$ 1.234,56 · 09:45 · 18/03</span></p>
            </div>
        </section>

        {{-- ESPACO, RAIO, SOMBRA --}}
        <section id="espaco" class="stack" aria-labelledby="t-espaco">
            <h2 id="t-espaco" class="h2">Espaço e forma</h2>
            <div class="ds-grid">
                <x-ui.card title="Escala de espaço (base 4px)">
                    @foreach ($espacos as $e)
                        <div class="ds-space"><code>--space-{{ $e }}</code><span class="ds-space__bar sp-{{ $e }}"></span></div>
                    @endforeach
                </x-ui.card>
                <x-ui.card title="Raios e sombras">
                    <div class="ds-shapes">
                        <div class="ds-shape ds-shape--control">controle</div>
                        <div class="ds-shape ds-shape--card">card</div>
                        <div class="ds-shape ds-shape--pill">pílula</div>
                        <div class="ds-shape ds-shape--shadow-md">sombra md (menus)</div>
                        <div class="ds-shape ds-shape--shadow-lg">sombra lg (modal)</div>
                    </div>
                    <p class="text-sm text-muted">Sombras só no que flutua. Cards usam borda.</p>
                </x-ui.card>
            </div>
        </section>

        {{-- ICONES --}}
        <section id="icones" class="stack" aria-labelledby="t-icones">
            <h2 id="t-icones" class="h2">Ícones</h2>
            <p class="text-muted">Lucide (traço 1,75, cantos arredondados), SVG local. Decorativos por padrão; com <code>label</code> viram imagem com nome acessível.</p>
            <ul class="ds-icons" role="list">
                @foreach ($icones as $i)
                    <li><x-icon :name="$i" class="icon-lg" /><code>{{ $i }}</code></li>
                @endforeach
            </ul>
        </section>

        {{-- BOTOES --}}
        <section id="botoes" class="stack" aria-labelledby="t-botoes">
            <h2 id="t-botoes" class="h2">Botões e links</h2>
            <div class="card stack">
                <div class="cluster">
                    <x-ui.button>Primário</x-ui.button>
                    <x-ui.button variant="accent" icon="calendar">Destaque (Agendar)</x-ui.button>
                    <x-ui.button variant="secondary">Secundário</x-ui.button>
                    <x-ui.button variant="ghost">Discreto</x-ui.button>
                    <x-ui.button variant="danger" icon="trash-2">Excluir</x-ui.button>
                </div>
                <div class="cluster">
                    <x-ui.button size="sm">Pequeno</x-ui.button>
                    <x-ui.button>Médio</x-ui.button>
                    <x-ui.button size="lg">Grande</x-ui.button>
                    <x-ui.button variant="secondary" class="btn--icon"><x-icon name="pencil" label="Editar" /></x-ui.button>
                </div>
                <div class="cluster">
                    <x-ui.button disabled>Desabilitado</x-ui.button>
                    <x-ui.button variant="accent" loading>Salvando</x-ui.button>
                    <x-ui.button variant="secondary" href="#botoes">Link com cara de botão</x-ui.button>
                    <a class="link-arrow" href="#botoes">Link com seta <x-icon name="arrow-right" /></a>
                    <a href="#botoes">Link de texto</a>
                </div>
                <p class="text-sm text-muted">Estados: hover escurece; foco pelo teclado mostra contorno de 3px; ativo desce 1px; carregando mostra spinner e bloqueia clique duplo.</p>
            </div>
        </section>

        {{-- FORMULARIOS --}}
        <section id="formularios" class="stack" aria-labelledby="t-forms">
            <h2 id="t-forms" class="h2">Formulários</h2>
            <div class="card">
                <div class="form-grid form-grid--2">
                    <x-ui.input name="ds_nome" label="Nome" placeholder="Como devemos te chamar?" autocomplete="off" />
                    <x-ui.input name="ds_tel" label="Celular" type="tel" hint="Usado para lembretes do horário." optional />
                    <x-ui.input name="ds_email" label="E-mail" type="email" value="email-invalido" error="Informe um e-mail válido, como nome@exemplo.com." />
                    <x-ui.input name="ds_bloq" label="Campo desabilitado" value="Não editável" disabled optional />
                    <x-ui.select name="ds_prof" label="Profissional" :options="['r' => 'Rafael', 'd' => 'Diego']" placeholder="Escolha" />
                    <x-ui.textarea name="ds_obs" label="Observações" optional hint="Ex.: prefiro máquina 2 nas laterais." rows="3" />
                </div>
                <div class="ds-grid">
                    <fieldset class="stack stack-sm">
                        <legend class="field__label">Checkbox</legend>
                        <x-ui.checkbox name="ds_c1" label="Lembrete na véspera" checked />
                        <x-ui.checkbox name="ds_c2" label="Novidades por e-mail" hint="Você pode cancelar quando quiser." />
                        <x-ui.checkbox name="ds_c3" label="Opção indisponível" disabled />
                    </fieldset>
                    <fieldset class="stack stack-sm">
                        <legend class="field__label">Radio</legend>
                        <x-ui.radio name="ds_pag" value="local" label="Pagar no local" checked />
                        <x-ui.radio name="ds_pag" value="pix" label="Pix antecipado" hint="Exemplo de opção futura." />
                        <x-ui.radio name="ds_pag" value="cartao" label="Cartão online" disabled />
                    </fieldset>
                    <div class="stack stack-sm">
                        <p class="field__label">Switch</p>
                        <x-ui.switch name="ds_s1" label="Agendamento online ativo" checked />
                        <x-ui.switch name="ds_s2" label="Aceitar encaixes" />
                        <x-ui.switch name="ds_s3" label="Desabilitado" disabled />
                    </div>
                </div>
                <fieldset class="stack stack-sm">
                    <legend class="field__label">Cartão selecionável (agendamento)</legend>
                    <div class="ds-grid">
                        <label class="option-card"><input type="radio" name="ds_card" checked><span class="stack stack-sm"><strong>Corte clássico</strong><span class="text-sm text-muted">45 min</span></span><strong class="numeric">R$ 55,00</strong><span class="option-card__check" aria-hidden="true"><x-icon name="check" class="icon-sm" /></span></label>
                        <label class="option-card"><input type="radio" name="ds_card"><span class="stack stack-sm"><strong>Degradê</strong><span class="text-sm text-muted">45 min</span></span><strong class="numeric">R$ 60,00</strong><span class="option-card__check" aria-hidden="true"><x-icon name="check" class="icon-sm" /></span></label>
                    </div>
                </fieldset>
            </div>
        </section>

        {{-- CONTEUDO --}}
        <section id="conteudo-ds" class="stack" aria-labelledby="t-conteudo">
            <h2 id="t-conteudo" class="h2">Cards, badges, avatar e foto</h2>
            <div class="ds-grid">
                <x-ui.card title="Card com ações">
                    <x-slot:actions><x-ui.button size="sm" variant="secondary">Editar</x-ui.button></x-slot:actions>
                    <p class="text-muted">Superfície com borda, sem sombra.</p>
                </x-ui.card>
                <x-ui.card variant="sunken" title="Card rebaixado"><p class="text-muted">Para agrupar sem competir.</p></x-ui.card>
                <x-ui.card title="Badges">
                    <div class="cluster">
                        <x-ui.badge>Neutro</x-ui.badge>
                        <x-ui.badge variant="success">Concluído</x-ui.badge>
                        <x-ui.badge variant="warning">Pendente</x-ui.badge>
                        <x-ui.badge variant="danger">Cancelado</x-ui.badge>
                        <x-ui.badge variant="info">Confirmado</x-ui.badge>
                        <x-ui.badge variant="accent" plain>Novo</x-ui.badge>
                        <x-ui.badge variant="sample">Exemplo</x-ui.badge>
                    </div>
                </x-ui.card>
                <x-ui.card title="Avatar">
                    <div class="cluster">
                        <x-ui.avatar name="Rafael Souza" size="sm" />
                        <x-ui.avatar name="Diego Martins" />
                        <x-ui.avatar name="Marcos Lima" size="lg" />
                        <x-ui.avatar name="Thiago Alves" size="xl" />
                    </div>
                </x-ui.card>
            </div>
            <div class="ds-grid">
                <x-ui.photo ratio="portrait" placeholder="Retrato do profissional" />
                <x-ui.photo ratio="square" placeholder="Trabalho finalizado" />
                <x-ui.photo ratio="landscape" placeholder="Ambiente da barbearia" />
            </div>
        </section>

        {{-- FEEDBACK --}}
        <section id="feedback" class="stack" aria-labelledby="t-feedback">
            <h2 id="t-feedback" class="h2">Feedback</h2>
            <div class="ds-grid">
                <x-ui.alert title="Informação">Lembretes são enviados na véspera às 9h.</x-ui.alert>
                <x-ui.alert variant="success" title="Salvo">Horários da equipe atualizados.</x-ui.alert>
                <x-ui.alert variant="warning" title="Atenção">3 clientes ainda não confirmaram.</x-ui.alert>
                <x-ui.alert variant="danger" title="Não foi possível salvar">O horário escolhido acabou de ser reservado.</x-ui.alert>
            </div>
            <div class="ds-grid">
                <x-ui.card title="Carregando">
                    <x-ui.loading label="Buscando horários…" />
                    <x-ui.skeleton :lines="3" />
                </x-ui.card>
                <x-ui.empty-state title="Nenhum agendamento hoje" icon="calendar">
                    Quando alguém agendar, aparece aqui.
                    <x-slot:action><x-ui.button size="sm" icon="plus">Novo agendamento</x-ui.button></x-slot:action>
                </x-ui.empty-state>
                <x-ui.error-state>
                    Verifique sua conexão e tente de novo. Se continuar, avise o proprietário.
                    <x-slot:action><x-ui.button size="sm" variant="secondary">Tentar novamente</x-ui.button></x-slot:action>
                </x-ui.error-state>
            </div>
        </section>

        {{-- NAVEGACAO --}}
        <section id="navegacao" class="stack" aria-labelledby="t-nav">
            <h2 id="t-nav" class="h2">Navegação</h2>
            <div class="card stack">
                <x-ui.breadcrumbs :items="[['label' => 'Painel', 'href' => '#'], ['label' => 'Clientes', 'href' => '#'], ['label' => 'João P.']]" />
                <x-ui.tabs :tabs="['dados' => 'Dados', 'historico' => 'Histórico', 'fidelidade' => 'Fidelidade']" label="Ficha do cliente">
                    <x-slot:dados><p class="text-muted">Aba 1. Use as setas ← → para trocar de aba.</p></x-slot:dados>
                    <x-slot:historico><p class="text-muted">Aba 2: histórico de atendimentos.</p></x-slot:historico>
                    <x-slot:fidelidade><p class="text-muted">Aba 3: pontos e recompensas.</p></x-slot:fidelidade>
                </x-ui.tabs>
                <div class="cluster">
                    <nav class="segmented" aria-label="Exemplo segmentado"><a class="segmented__item" href="#navegacao" aria-current="page">Dia</a><a class="segmented__item" href="#navegacao">Semana</a><a class="segmented__item" href="#navegacao">Lista</a></nav>
                </div>
                <ol class="steps" aria-label="Etapas (exemplo)">
                    <li class="steps__item is-done"><span class="steps__label">1. Serviços</span></li>
                    <li class="steps__item" aria-current="step"><span class="steps__label">2. Profissional</span></li>
                    <li class="steps__item"><span class="steps__label">3. Horário</span></li>
                    <li class="steps__item"><span class="steps__label">4. Confirmação</span></li>
                </ol>
                <nav class="ds-nav" aria-label="Menu lateral (exemplo)">
                    <a class="nav-link" href="#navegacao" aria-current="page"><x-icon name="layout-dashboard" /> Hoje</a>
                    <a class="nav-link" href="#navegacao"><x-icon name="calendar-days" /> Agenda</a>
                    <a class="nav-link" href="#navegacao"><x-icon name="users" /> Clientes</a>
                </nav>
            </div>
        </section>

        {{-- DADOS --}}
        <section id="dados" class="stack" aria-labelledby="t-dados">
            <h2 id="t-dados" class="h2">Tabela e paginação</h2>
            <x-ui.table caption="Atendimentos (exemplo) — no celular vira lista" stacked>
                <thead><tr><th scope="col">Cliente</th><th scope="col">Serviço</th><th scope="col">Situação</th><th scope="col" class="num">Valor</th></tr></thead>
                <tbody>
                    <tr><td data-label="Cliente">João P.</td><td data-label="Serviço">Degradê</td><td data-label="Situação"><x-ui.badge variant="success">Concluído</x-ui.badge></td><td data-label="Valor" class="num">R$ 60,00</td></tr>
                    <tr><td data-label="Cliente">Lucas M.</td><td data-label="Serviço">Corte + barba</td><td data-label="Situação"><x-ui.badge variant="info">Confirmado</x-ui.badge></td><td data-label="Valor" class="num">R$ 95,00</td></tr>
                    <tr><td data-label="Cliente">Pedro H.</td><td data-label="Serviço">Corte clássico</td><td data-label="Situação"><x-ui.badge variant="warning">Pendente</x-ui.badge></td><td data-label="Valor" class="num">R$ 55,00</td></tr>
                </tbody>
            </x-ui.table>
            {{ $paginator->links() }}
        </section>

        {{-- MODAIS E MENUS --}}
        <section id="sobreposicoes" class="stack" aria-labelledby="t-overlay">
            <h2 id="t-overlay" class="h2">Modal, confirmação e dropdown</h2>
            <div class="card"><div class="cluster">
                <x-ui.button variant="secondary" data-dialog-open="ds-modal">Abrir modal</x-ui.button>
                <x-ui.button variant="danger" data-dialog-open="ds-confirm">Excluir (confirmação)</x-ui.button>
                <x-ui.dropdown label="Ações do agendamento">
                    <button type="button" class="dropdown__item"><x-icon name="pencil" /> Editar</button>
                    <button type="button" class="dropdown__item"><x-icon name="calendar" /> Remarcar</button>
                    <div class="dropdown__separator"></div>
                    <button type="button" class="dropdown__item dropdown__item--danger"><x-icon name="trash-2" /> Excluir</button>
                </x-ui.dropdown>
            </div></div>
            <x-ui.modal id="ds-modal" title="Título do modal">
                <p>Foco fica preso aqui dentro; Esc fecha; no celular vira uma folha na base da tela.</p>
                <x-slot:footer>
                    <button type="button" class="btn btn--secondary" data-dialog-close>Cancelar</button>
                    <button type="button" class="btn" data-dialog-close>Salvar</button>
                </x-slot:footer>
            </x-ui.modal>
            <x-ui.confirm id="ds-confirm" title="Excluir este serviço?" confirm-label="Excluir serviço">
                Agendamentos futuros com este serviço precisam ser remarcados antes. O histórico é mantido.
            </x-ui.confirm>
        </section>

        {{-- SUPERFICIES: os mesmos componentes no claro e no escuro --}}
        <section id="superficies" class="stack" aria-labelledby="t-superficies">
            <h2 id="t-superficies" class="h2">Superfícies clara e escura</h2>
            <div class="ds-grid">
                @foreach (['clara' => 'Clara (painel)', 'escura' => 'Escura (site)'] as $sup => $rotulo)
                    <div class="ds-surface" data-direcao="{{ $direcao }}" data-superficie="{{ $sup }}">
                        <p class="eyebrow">{{ $rotulo }}</p>
                        <p class="h3">Corte, barba e boa conversa.</p>
                        <p class="text-muted">Texto secundário com contraste AA.</p>
                        <div class="cluster">
                            <x-ui.button variant="accent" size="sm">Agendar</x-ui.button>
                            <x-ui.button size="sm">Primário</x-ui.button>
                            <x-ui.button variant="secondary" size="sm">Secundário</x-ui.button>
                        </div>
                        <x-ui.input :name="'ds_sup_'.$sup" label="Campo" placeholder="Digite aqui" optional />
                        <div class="cluster"><x-ui.badge variant="success">Concluído</x-ui.badge><x-ui.badge variant="warning">Pendente</x-ui.badge><x-ui.badge variant="danger">Cancelado</x-ui.badge></div>
                    </div>
                @endforeach
            </div>
        </section>
    </main>
</x-layouts.document>

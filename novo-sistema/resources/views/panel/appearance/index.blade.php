{{--
    Configuracoes > Aparencia (temas-visuais.md). Cada cartao mostra o tema de
    verdade: a miniatura carrega data-tema e usa os mesmos tokens do site e do
    painel (nome e frase reais da barbearia, nada inventado). "Ver previa"
    abre o painel inteiro no tema, sem gravar; "Usar este tema" grava.
--}}
@php
    $marca = \App\Modules\SiteContent\Support\SiteSettings::current();
    $nome = $marca->name();
    $frase = $marca->has('tagline') ? $marca->get('tagline') : $nome;
@endphp
<x-layouts.staff title="Aparência">
    <header class="page-head">
        <div class="stack stack-sm">
            <h1 class="page-head__title">Aparência</h1>
            <p class="text-muted">Escolha a identidade visual da barbearia. O tema muda cores, letras e detalhes do site, da conta dos clientes e do painel de toda a equipe. Telas, textos, preços e regras continuam iguais.</p>
        </div>
        <div class="cluster">
            <x-ui.button :href="route('home')" variant="secondary" icon="external-link" target="_blank" rel="noopener">Ver o site<span class="visually-hidden"> (abre em outra aba)</span></x-ui.button>
        </div>
    </header>

    <section class="stack" aria-labelledby="tema-atual">
        <h2 id="tema-atual" class="eyebrow">Tema em uso</h2>
        <p class="theme-current"><strong>{{ $current->name() }}</strong> <span class="text-muted">· {{ $current->palette() }}</span></p>
    </section>

    <ul class="theme-grid" role="list" aria-label="Temas disponíveis">
        @foreach ($themes as $t)
            @php $emUso = $t->is($current); @endphp
            <li @class(['theme-card', 'is-current' => $emUso]) data-theme-card="{{ $t->key }}">
                <div class="theme-mini" data-tema="{{ $t->key }}" data-superficie="{{ $t->surface('site') }}" aria-hidden="true">
                    <div class="theme-mini__site">
                        <div class="theme-mini__bar">
                            <span class="theme-mini__seal">{{ mb_substr($nome, 0, 1) }}</span>
                            <span class="theme-mini__brand">{{ $nome }}</span>
                            <span class="theme-mini__nav"><i></i><i></i><i></i></span>
                        </div>
                        <p class="theme-mini__eyebrow">Barbearia</p>
                        <p class="theme-mini__title">{{ \Illuminate\Support\Str::limit($frase, 36) }}</p>
                        <span class="theme-mini__btn">Agendar horário</span>
                        <span class="theme-mini__motif"></span>
                    </div>
                    <div class="theme-mini__panel" data-superficie="{{ $t->surface('panel') }}">
                        <div class="theme-mini__side" data-superficie="{{ $t->surface('sidebar') }}">
                            <i class="is-active"></i><i></i><i></i><i></i>
                        </div>
                        <div class="theme-mini__work">
                            <p class="theme-mini__h">Agenda</p>
                            <div class="theme-mini__ev theme-mini__ev--now"><b>09:00</b> Em atendimento</div>
                            <div class="theme-mini__ev"><b>09:45</b> Confirmado</div>
                            <div class="theme-mini__ev theme-mini__ev--done"><b>08:30</b> Concluído</div>
                        </div>
                    </div>
                </div>

                <div class="theme-card__body">
                    <div class="theme-card__head">
                        <h2 class="theme-card__name">{{ $t->name() }}</h2>
                        @if ($emUso)<x-ui.badge variant="success">Em uso</x-ui.badge>@endif
                    </div>
                    <p class="theme-card__palette">{{ $t->palette() }}</p>
                    <ul class="theme-swatches" role="list" aria-label="Paleta">
                        @foreach ($t->swatches() as [$rotulo, $cor])
                            <li><span class="theme-swatch" data-cor="{{ $cor }}"></span>{{ $rotulo }}</li>
                        @endforeach
                    </ul>
                    <p class="theme-card__desc text-muted">{{ $t->description() }}</p>
                    <p class="theme-card__meta text-muted">Letra: {{ $t->typeface() }} · Detalhe: {{ $t->motif() }}</p>
                    <div class="theme-card__actions">
                        <x-ui.button :href="route('panel.appearance.preview', $t->key)" variant="secondary" size="sm" icon="eye">Ver prévia<span class="visually-hidden"> do tema {{ $t->name() }}</span></x-ui.button>
                        @unless ($emUso)
                            <form method="POST" action="{{ route('panel.appearance.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="theme" value="{{ $t->key }}">
                                <x-ui.button type="submit" variant="primary" size="sm">Usar este tema<span class="visually-hidden"> ({{ $t->name() }})</span></x-ui.button>
                            </form>
                        @endunless
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    {{-- Cor de cada amostra: CSS com nonce (a CSP nao aceita style=""). --}}
    @push('head')
        <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            @foreach ($themes as $t)
                @foreach ($t->swatches() as [$rotulo, $cor])
                    .theme-swatch[data-cor="{{ $cor }}"] { background: {{ $cor }}; }
                @endforeach
            @endforeach
        </style>
    @endpush
</x-layouts.staff>

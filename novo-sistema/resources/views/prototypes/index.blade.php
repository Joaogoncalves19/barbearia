<x-layouts.document title="Telas de referência" :direction="$direcao" surface="clara" area="panel" :noindex="true">
    @include('partials.prototype-banner', ['direcao' => $direcao])
    <main id="conteudo" class="container-narrow stack stack-lg proto-index">
        <header class="stack">
            <p class="eyebrow">Fase 1 · validação visual</p>
            <h1 class="h1">Telas de referência</h1>
            <p class="lead">Protótipos com dados de exemplo para aprovar a identidade visual antes de construir as telas reais. Troque a direção (A/B) na faixa amarela.</p>
        </header>
        <ul class="stack" role="list">
            @foreach ([
                ['prototypes.home', 'Tela 1 — Home pública', 'Site de marca: hero, serviços, equipe, avaliações, localização.'],
                ['prototypes.services', 'Tela 2 — Serviços', 'Menu completo com preço e duração.'],
                ['prototypes.booking', 'Tela 3 — Agendamento', 'Estrutura em 4 etapas, sem cadastro prévio.'],
                ['prototypes.dashboard', 'Tela 4 — Painel "Hoje"', 'Dashboard administrativo focado no dia.'],
                ['prototypes.agenda', 'Tela 5 — Agenda', 'Colunas por profissional e visão em lista.'],
                ['prototypes.design-system', 'Design System', 'Tokens e todos os componentes com estados.'],
            ] as [$rota, $titulo, $desc])
                <li class="card card--link">
                    <a class="title" href="{{ route($rota, $q) }}">{{ $titulo }}</a>
                    <p class="text-muted">{{ $desc }}</p>
                </li>
            @endforeach
        </ul>
    </main>
</x-layouts.document>

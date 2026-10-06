{{-- ASSINATURA (D-03): planos ativos e versao atual (preco e servicos incluidos). --}}
<x-site.page title="Assinatura" :description="'Planos de assinatura da '.$cfg->name().': serviços incluídos todo mês.'" current="plans">
    <header class="masthead" aria-labelledby="planos-titulo">
        <div class="container masthead__inner">
            <p class="eyebrow">Assinatura</p>
            <h1 id="planos-titulo" class="display caps masthead__title">Cliente de casa, todo mês</h1>
            <p class="lead">Os serviços incluídos no plano saem sem custo no agendamento enquanto a assinatura estiver em dia.</p>
        </div>
    </header>

    <div class="container page-body stack stack-xl">
        <ul class="plan-list plan-list--wide" role="list">
            @foreach ($plans as $p)
                <li class="plan-card">
                    <h2 class="plan-card__name">{{ $p->name }}</h2>
                    <p class="plan-card__price figure">{{ $p->currentVersion?->priceLabel() }}</p>
                    @if ($p->description)<p class="text-muted">{{ $p->description }}</p>@endif
                    @if ($p->currentVersion && $p->currentVersion->services->isNotEmpty())
                        <ul class="plan-card__list" role="list">
                            @foreach ($p->currentVersion->services as $s)
                                <li><x-icon name="check" /> {{ $s->name }}</li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
        <section class="how-to" aria-labelledby="como-assinar">
            <h2 id="como-assinar" class="h3 caps">Como assinar</h2>
            @if ($onlineSignup)
                <p>Agende um serviço incluído no plano: na confirmação do agendamento aparece a opção de assinar, com pagamento seguro pelo Stripe.</p>
                <div><x-ui.button :href="route('booking.services')" variant="accent" icon="calendar">Agendar e assinar</x-ui.button></div>
            @else
                <p>Fale com a barbearia no seu próximo atendimento: a equipe envia o link de pagamento.</p>
            @endif
        </section>
    </div>
</x-site.page>

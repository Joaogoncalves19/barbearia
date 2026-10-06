{{-- ASSINATURA (Fase 11; D-03): planos ativos e versao atual (preco e servicos incluidos). --}}
<x-site.page title="Assinatura" :description="'Planos de assinatura da '.$cfg->name().': serviços incluídos todo mês.'" current="plans">
    <section class="section section--tight page-head-site" aria-labelledby="planos-titulo">
        <div class="container stack">
            <p class="eyebrow">Assinatura</p>
            <h1 id="planos-titulo" class="h1">Cliente de casa, todo mês</h1>
            <p class="lead">Os serviços incluídos no plano saem sem custo no agendamento enquanto a assinatura estiver em dia.</p>
        </div>
    </section>
    <div class="barber-stripe barber-stripe--thin" aria-hidden="true"></div>

    <section class="section section--tight">
        <div class="container stack stack-lg">
            <ul class="plan-list plan-list--wide" role="list">
                @foreach ($plans as $p)
                    <li class="plan-card">
                        <h2 class="plan-card__name">{{ $p->name }}</h2>
                        <p class="plan-card__price">{{ $p->currentVersion?->priceLabel() }}</p>
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
            <div class="stack stack-sm">
                <h2 class="h3">Como assinar</h2>
                @if ($onlineSignup)
                    <p>Agende um serviço incluído no plano: na confirmação do agendamento aparece a opção de assinar, com pagamento seguro pelo Stripe.</p>
                    <div><x-ui.button :href="route('booking.services')" variant="accent" icon="calendar">Agendar e assinar</x-ui.button></div>
                @else
                    <p>Fale com a barbearia no seu próximo atendimento: a equipe envia o link de pagamento.</p>
                @endif
            </div>
        </div>
    </section>
</x-site.page>

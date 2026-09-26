{{--
    TELA DE REFERENCIA 3 — Estrutura do agendamento (4 etapas).
    Prototipo: roda so no navegador, com dados de exemplo, e nao grava nada.
    No fluxo real (Fase 5) horarios e orcamento vem do servidor.
--}}
<x-layouts.site title="Agendar (referência)" :direction="$direcao" :brand="$brand" :nav="$siteNav"
    :booking-url="route('prototypes.booking', $q)" :home-url="route('prototypes.home', $q)" prototype :bottom-bar="false">

    @push('head')
        <script type="application/json" id="booking-sample-data" nonce="{{ Vite::cspNonce() }}">@json($bookingJson)</script>
    @endpush

    <div class="container booking" x-data="booking">
        <div class="stack stack-lg">
            <header class="stack stack-sm">
                <p class="eyebrow">Agendamento online</p>
                <h1 class="h2">Reserve seu horário</h1>
                <p class="text-muted">Veja preços e horários livres sem criar conta. Você só se identifica para confirmar.</p>
            </header>

            <ol class="steps" aria-label="Etapas do agendamento">
                <li class="steps__item" x-bind:class="step1State" x-bind:aria-current="step1Current"><span class="steps__label">1. Serviços</span></li>
                <li class="steps__item" x-bind:class="step2State" x-bind:aria-current="step2Current"><span class="steps__label">2. Profissional</span></li>
                <li class="steps__item" x-bind:class="step3State" x-bind:aria-current="step3Current"><span class="steps__label">3. Data e horário</span></li>
                <li class="steps__item" x-bind:class="step4State" x-bind:aria-current="step4Current"><span class="steps__label">4. Confirmação</span></li>
            </ol>

            {{-- 1. Servicos --}}
            <section class="booking__step" x-show="isStep1" aria-labelledby="etapa-1">
                <h2 class="h3" id="etapa-1" tabindex="-1" data-step-title>O que vamos fazer?</h2>
                @foreach ($services as $grupo)
                    <fieldset class="booking__options">
                        <legend class="menu-group__title">{{ $grupo['category'] }}</legend>
                        @foreach ($grupo['items'] as $item)
                            <label class="option-card">
                                <input type="checkbox" name="servicos[]" value="{{ $item['id'] }}">
                                <span class="stack stack-sm">
                                    <strong>{{ $item['name'] }}</strong>
                                    <span class="text-sm text-muted">{{ $item['minutes'] }} min · {{ $item['description'] }}</span>
                                </span>
                                <strong class="numeric">{{ $item['price']->format() }}</strong>
                                <span class="option-card__check" aria-hidden="true"><x-icon name="check" class="icon-sm" /></span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </section>

            {{-- 2. Profissional --}}
            <section class="booking__step" x-show="isStep2" x-cloak aria-labelledby="etapa-2">
                <h2 class="h3" id="etapa-2" tabindex="-1" data-step-title>Com quem?</h2>
                <fieldset class="booking__options booking__options--pros">
                    <legend class="visually-hidden">Profissional</legend>
                    <label class="option-card">
                        <input type="radio" name="profissional" value="qualquer">
                        <span class="avatar avatar--lg" aria-hidden="true"><x-icon name="users" /></span>
                        <span class="stack stack-sm"><strong>Qualquer profissional</strong><span class="text-sm text-muted">Mostra mais horários</span></span>
                        <span class="option-card__check" aria-hidden="true"><x-icon name="check" class="icon-sm" /></span>
                    </label>
                    @foreach ($pros as $pro)
                        <label class="option-card">
                            <input type="radio" name="profissional" value="{{ $pro['id'] }}">
                            <x-ui.avatar :name="$pro['name']" size="lg" />
                            <span class="stack stack-sm"><strong>{{ $pro['name'] }}</strong><span class="text-sm text-muted">{{ $pro['role'] }}</span></span>
                            <span class="option-card__check" aria-hidden="true"><x-icon name="check" class="icon-sm" /></span>
                        </label>
                    @endforeach
                </fieldset>
            </section>

            {{-- 3. Data e horario --}}
            <section class="booking__step" x-show="isStep3" x-cloak aria-labelledby="etapa-3">
                <h2 class="h3" id="etapa-3" tabindex="-1" data-step-title>Quando?</h2>
                <div class="days" role="group" aria-label="Dias disponíveis">
                    @foreach ($days as $d)
                        <button type="button" class="day" data-day="{{ $d['value'] }}" aria-pressed="false" @disabled($d['disabled']) aria-label="{{ $d['long'] }}{{ $d['disabled'] ? ' (fechado)' : '' }}">
                            <span>{{ $d['weekday'] }}</span><strong>{{ $d['day'] }}</strong>
                        </button>
                    @endforeach
                </div>
                @foreach ($slots as $periodo => $horarios)
                    <div class="stack stack-sm">
                        <h3 class="field__label">{{ $periodo }}</h3>
                        <div class="slots" role="group" aria-label="Horários da {{ mb_strtolower($periodo) }}">
                            @foreach ($horarios as $h)
                                <button type="button" class="slot" data-slot="{{ $h }}" aria-pressed="false">{{ $h }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <x-ui.alert variant="info">Horários de exemplo. No sistema real só aparecem horários livres para o profissional e a duração escolhidos.</x-ui.alert>
            </section>

            {{-- 4. Identificacao e confirmacao --}}
            <section class="booking__step" x-show="isStep4" x-cloak aria-labelledby="etapa-4">
                <h2 class="h3" id="etapa-4" tabindex="-1" data-step-title>Quase lá</h2>
                <p class="text-muted">Primeira vez? Crie sua conta aqui mesmo. Já tem conta? <a href="#entrar-exemplo">Entre</a>.</p>
                <div class="form-grid form-grid--2">
                    <x-ui.input name="nome" label="Nome" autocomplete="name" />
                    <x-ui.input name="telefone" label="Celular (WhatsApp)" type="tel" autocomplete="tel" inputmode="tel" hint="Para lembretes e confirmação." />
                    <x-ui.input name="email" label="E-mail" type="email" autocomplete="email" />
                    <x-ui.input name="senha" label="Crie uma senha" type="password" autocomplete="new-password" hint="Mínimo 8 caracteres, com letra e número." />
                </div>
                <x-ui.checkbox name="lembrete" label="Quero receber lembrete no dia anterior" checked />
                <x-ui.alert variant="success" title="Protótipo" x-show="confirmed" x-cloak>
                    Neste protótipo nada é gravado. No sistema real, aqui aparece a confirmação com opção de adicionar à agenda.
                </x-ui.alert>
            </section>

            <div class="cluster booking__nav">
                <x-ui.button variant="ghost" icon="chevron-left" x-on:click="back" x-bind:disabled="isFirst">Voltar</x-ui.button>
                <button type="button" class="btn btn--accent" x-on:click="next" x-bind:disabled="cannotAdvance"><span x-text="nextLabel">Continuar</span></button>
            </div>
        </div>

        {{-- Resumo (lateral no desktop, barra fixa no celular) --}}
        <aside class="booking__summary" aria-label="Resumo do agendamento">
            <x-ui.card class="booking__summary-card" title="Resumo">
                <dl class="summary-list">
                    <div><dt>Serviços</dt><dd x-text="servicesLabel">Nenhum serviço escolhido</dd></div>
                    <div><dt>Duração</dt><dd x-text="durationLabel">—</dd></div>
                    <div><dt>Profissional</dt><dd x-text="proLabel">—</dd></div>
                    <div><dt>Quando</dt><dd x-text="whenLabel">—</dd></div>
                </dl>
                <p class="summary-total"><span>Total estimado</span> <strong x-text="totalLabel">R$ 0,00</strong></p>
                <p class="text-xs text-muted">Pagamento no local. Descontos (cupom, fidelidade) aparecem aqui quando aplicáveis.</p>
            </x-ui.card>
        </aside>

        <div class="booking__bar" aria-live="polite">
            <span class="stack stack-sm">
                <span class="text-xs text-muted" x-text="countLabel">Nenhum serviço</span>
                <strong class="numeric" x-text="totalLabel">R$ 0,00</strong>
            </span>
            <button type="button" class="btn btn--accent" x-on:click="next" x-bind:disabled="cannotAdvance"><span x-text="nextLabel">Continuar</span></button>
        </div>
    </div>
</x-layouts.site>

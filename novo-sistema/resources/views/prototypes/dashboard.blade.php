{{-- TELA DE REFERENCIA 4 — Painel "Hoje". Numeros de EXEMPLO. --}}
@php
    $fmt = fn ($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    $statusBadge = ['done' => ['success', 'Concluído'], 'confirmed' => ['info', 'Confirmado'], 'pending' => ['warning', 'Aguardando confirmação']];
@endphp
<x-layouts.panel title="Hoje (referência)" :direction="$direcao" :brand="$brand" :nav="$panelNav"
    user-name="Ana Recepção" user-role="Recepção" prototype>

    <header class="page-head">
        <div class="stack stack-sm">
            <p class="text-muted">Quinta-feira, 12 de março · <x-ui.badge variant="sample">Dados de exemplo</x-ui.badge></p>
            <h1 class="page-head__title">Bom dia, Ana</h1>
        </div>
        <div class="cluster">
            <x-ui.button variant="secondary" icon="calendar-days" :href="route('prototypes.agenda', $q)">Ver agenda</x-ui.button>
            <x-ui.button icon="plus" data-dialog-open="novo-agendamento">Novo agendamento</x-ui.button>
        </div>
    </header>

    {{-- No maximo 4 indicadores: o que importa HOJE. O resto vai para Relatorios. --}}
    <section class="stats" aria-label="Resumo do dia">
        <div class="stat"><span class="stat__label">Atendimentos hoje</span><span class="stat__value">18</span><span class="stat__foot">6 concluídos · 12 a seguir</span></div>
        <div class="stat"><span class="stat__label">Caixa do dia</span><span class="stat__value">R$ 640,00</span><span class="stat__foot">só atendimentos fechados</span></div>
        <div class="stat"><span class="stat__label">Próximo cliente</span><span class="stat__value">13:30</span><span class="stat__foot">André S. · com Rafael</span></div>
        <div class="stat"><span class="stat__label">Ocupação</span><span class="stat__value">72%</span><span class="stat__foot">da jornada da equipe</span></div>
    </section>

    <div class="dashboard-grid">
        <x-ui.card title="Agenda de hoje">
            <x-slot:actions>
                <x-ui.button variant="ghost" size="sm" icon-right="arrow-right" :href="route('prototypes.agenda', $q)">Agenda completa</x-ui.button>
            </x-slot:actions>
            <ul class="appt-list">
                @foreach ($today->take(8) as $i => $e)
                    <li @class(['appt', 'is-next' => $i === $nextIndex])>
                        <span class="appt__time">{{ $fmt($e['start']) }}</span>
                        <span class="appt__who">
                            <strong>{{ $e['client'] }} @if ($i === $nextIndex)<span class="visually-hidden">(próximo)</span>@endif</strong>
                            <span>{{ $e['service'] }} · {{ $e['pro'] }}</span>
                        </span>
                        <x-ui.badge :variant="$statusBadge[$e['status']][0]">{{ $statusBadge[$e['status']][1] }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <div class="stack">
            <x-ui.card title="Precisa de atenção">
                <ul class="task-list">
                    <li><x-ui.alert variant="warning" title="3 clientes sem confirmação">Hoje à tarde. <a href="#">Enviar lembrete</a></x-ui.alert></li>
                    <li><x-ui.alert variant="danger" title="Estoque baixo: pomada modeladora">Restam 2 unidades.</x-ui.alert></li>
                    <li><x-ui.alert variant="info" title="Diego sai às 17h hoje">Ausência registrada pelo gerente.</x-ui.alert></li>
                </ul>
            </x-ui.card>
            <x-ui.card title="Caixa">
                <dl class="summary-list">
                    <div><dt>Dinheiro</dt><dd class="numeric">R$ 120,00</dd></div>
                    <div><dt>Pix</dt><dd class="numeric">R$ 380,00</dd></div>
                    <div><dt>Cartão</dt><dd class="numeric">R$ 140,00</dd></div>
                </dl>
                <p class="summary-total"><span>Total</span> <strong>R$ 640,00</strong></p>
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal id="novo-agendamento" title="Novo agendamento">
        <p>Formulário real na Fase 5. Aqui só a estrutura do modal (foco preso, Esc fecha, vira folha no celular).</p>
        <div class="form-grid">
            <x-ui.input name="cliente" label="Cliente" placeholder="Buscar por nome ou telefone" />
            <x-ui.select name="servico" label="Serviço" :options="['corte' => 'Corte clássico — 45 min', 'barba' => 'Barba — 30 min']" placeholder="Escolha" />
        </div>
        <x-slot:footer>
            <button type="button" class="btn btn--secondary" data-dialog-close>Cancelar</button>
            <button type="button" class="btn" data-dialog-close>Continuar</button>
        </x-slot:footer>
    </x-ui.modal>
</x-layouts.panel>

<?php

/*
|--------------------------------------------------------------------------
| Matriz de permissoes (DENY BY DEFAULT)
|--------------------------------------------------------------------------
|
| Documentacao: docs/reconstrucao/papeis-permissoes.md
|
| - Toda habilidade precisa estar declarada. Uma Gate e registrada para cada
|   uma (AppServiceProvider). Habilidade nao declarada nao tem Gate e o
|   Laravel nega.
| - Cada papel lista EXPLICITAMENTE o que pode. Papel ausente = nada. Nao
|   existe curinga: nem o proprietario recebe "tudo" (briefing da Fase 3).
| - 'abilities' valem so para a EQUIPE (guard web, model User).
|   'customer_abilities' valem so para CLIENTES (guard customer) e sao
|   concedidas a todo cliente ativo. Um tipo nunca recebe as do outro.
| - Habilidade = capacidade do papel ("pode ver clientes?"). Acesso a um
|   REGISTRO de outra pessoa (acesso horizontal) e decidido pelas Policies
|   (app/Modules/<M>/Policies), sempre alem da Gate.
|
| Habilidades de modulos futuros ja estao declaradas para que as proximas
| fases so as usem; a tela de cada uma chega na fase do modulo.
|
*/

return [

    'abilities' => [
        // Painel e conta propria
        'panel.access' => 'Acessar o painel da equipe (e a própria conta)',
        'system.health.view' => 'Ver a saúde do sistema',

        // Identidade e auditoria (Fase 3)
        'users.manage' => 'Criar, editar, desativar e redefinir a senha de usuários da equipe',
        'audit.view' => 'Ver a trilha de auditoria',

        // Clientes (Fase 4+)
        'customers.view' => 'Ver qualquer cliente',
        'customers.view_own' => 'Ver só os clientes que atendeu ou vai atender',
        'customers.create' => 'Cadastrar clientes',
        'customers.update' => 'Editar clientes',
        'customers.view_cpf' => 'Ver o CPF completo do cliente',
        'customers.anonymize' => 'Anonimizar cliente (LGPD)',

        // Agenda (Fase 5)
        'appointments.view_all' => 'Ver a agenda de todos os profissionais',
        'appointments.view_own' => 'Ver só a própria agenda',
        'appointments.manage' => 'Criar e remarcar qualquer agendamento',
        'appointments.manage_own' => 'Criar e remarcar agendamentos da própria agenda',
        'appointments.cancel' => 'Cancelar qualquer agendamento',

        // Agenda (Fase 5). agenda.view abre a tela da agenda; QUAIS agendamentos
        // aparecem (todos ou so os proprios) vem de appointments.view_all/view_own.
        'agenda.view' => 'Consultar a agenda',

        // Configuracao da agenda (Fase 5). Consultar a agenda NAO da direito a
        // configura-la: cada configuracao tem a sua habilidade.
        'schedule.settings' => 'Configurar horário de funcionamento e regras da agenda',
        'schedule.working_hours' => 'Configurar expediente e pausas dos profissionais',
        'schedule.time_off' => 'Lançar e remover folgas dos profissionais',
        'schedule.blocks' => 'Criar e remover bloqueios de agenda',

        // Catalogo de servicos e categorias (Fase 4). As categorias usam as
        // mesmas habilidades dos servicos (fazem parte do mesmo catalogo).
        'services.view' => 'Ver serviços e categorias',
        'services.create' => 'Criar serviços e categorias',
        'services.update' => 'Editar serviços e categorias (nome, descrição, duração, categoria)',
        'services.toggle' => 'Ativar e desativar serviços e categorias',
        'services.price' => 'Alterar o preço dos serviços',
        'services.display' => 'Alterar ordem, destaque, visibilidade no site e imagem dos serviços',

        // Profissionais (Fase 4). Expediente/folgas entram com a agenda (Fase 5).
        'professionals.view' => 'Ver profissionais',
        'professionals.create' => 'Cadastrar profissionais',
        'professionals.update' => 'Editar profissionais (dados e conta de acesso vinculada)',
        'professionals.toggle' => 'Ativar e desativar profissionais',
        'professionals.services' => 'Definir quais serviços cada profissional executa',
        'professionals.display' => 'Alterar ordem, destaque, visibilidade no site, foto e apresentação dos profissionais',

        // Atendimento (Fase 6). view/manage valem para todos; view_own e
        // manage_own so para os atendimentos do proprio profissional (Policy).
        'attendances.view' => 'Ver todos os atendimentos',
        'attendances.view_own' => 'Ver só os próprios atendimentos',
        'attendances.manage' => 'Abrir, iniciar, editar e concluir qualquer atendimento',
        'attendances.manage_own' => 'Abrir, iniciar, editar e concluir os próprios atendimentos',
        'attendances.discount' => 'Aplicar e retirar desconto no atendimento',
        'attendances.cancel' => 'Cancelar qualquer atendimento não concluído',

        // Pagamentos e caixa (Fase 6). Receber = registrar o pagamento ao
        // concluir (entra no caixa aberto); nao da acesso ao caixa em si.
        'payments.receive' => 'Registrar pagamento ao concluir o atendimento',
        'payments.refund' => 'Estornar pagamento',
        'cash.view' => 'Ver o caixa (movimentações, resumo e fechamentos)',
        'cash.open' => 'Abrir o caixa',
        'cash.move' => 'Lançar suprimento e sangria',
        'cash.close' => 'Fechar o caixa',

        // Produtos e estoque (Fase 6). O saldo nunca e editado: so muda por
        // movimentacao (entrada, saida, ajuste de inventario, estorno).
        'products.view' => 'Ver produtos',
        'products.create' => 'Cadastrar produtos',
        'products.update' => 'Editar produtos (dados, preço e custo)',
        'products.toggle' => 'Ativar, desativar e excluir (sem histórico) produtos',
        'stock.view' => 'Ver estoque e movimentações',
        'stock.receive' => 'Registrar entrada de estoque',
        'stock.issue' => 'Registrar saída e perda de estoque',
        'stock.adjust' => 'Ajustar inventário e estornar movimentações',

        // Comissao, gorjeta, vales e repasse (Fase 7). Cada acao tem a sua
        // habilidade: ver nao da direito a configurar, pagar ou corrigir.
        // view_own: o profissional ve SO o proprio extrato (Gate viewLedger).
        'commissions.view' => 'Ver comissões, gorjetas, vales e saldo de todos os profissionais',
        'commissions.view_own' => 'Ver o próprio extrato (comissões, gorjetas, vales e repasses)',
        'commissions.configure' => 'Configurar regras de comissão',
        'commissions.correct' => 'Lançar ajuste (correção) de comissão ou de gorjeta',
        'commissions.history' => 'Consultar o histórico de regras de comissão e de correções',
        'payouts.view' => 'Ver repasses',
        'payouts.create' => 'Registrar repasse (pagar o profissional)',
        'payouts.reverse' => 'Estornar repasse',
        'advances.create' => 'Lançar vale (adiantamento)',
        'advances.reverse' => 'Estornar vale',
        // Despesas, metas, DRE e relatorios gerais: fases seguintes.
        'reports.view' => 'Ver relatórios',

        // Promocoes, fidelidade e vale-presente (Fase 8). Cada acao tem a sua
        // habilidade; aplicar cupom/pontos no balcao nao da direito a
        // configurar nem a dar desconto manual (attendances.discount).
        'coupons.view' => 'Ver cupons e usos',
        'coupons.manage' => 'Criar, editar, ativar e desativar cupons',
        'promotions.apply' => 'Aplicar cupom ou pontos do cliente no atendimento',
        'promotions.configure' => 'Configurar fidelidade, aniversário e indicação',
        'loyalty.view' => 'Ver saldo e extrato de pontos dos clientes',
        'loyalty.adjust' => 'Ajustar pontos de cliente (com motivo)',
        'gift_cards.view' => 'Ver vales-presente',
        'gift_cards.sell' => 'Vender vale-presente (entra no caixa)',
        'gift_cards.cancel' => 'Cancelar vale-presente (devolve o valor pelo caixa)',

        // Assinaturas (Fase 9). Cada acao tem a sua habilidade; ninguem ve as
        // chaves do Stripe (ficam so em variaveis de ambiente).
        'subscriptions.view' => 'Ver assinaturas e assinantes',
        'subscriptions.payments' => 'Ver pagamentos e reembolsos de assinatura',
        'subscriptions.create' => 'Gerar link de pagamento de assinatura para um cliente',
        'subscriptions.cancel' => 'Cancelar assinatura (no fim do período ou imediatamente)',
        'subscriptions.reactivate' => 'Reativar assinatura com cancelamento agendado',
        'subscriptions.refund' => 'Reembolsar pagamento de assinatura (pelo Stripe)',
        'subscriptions.history' => 'Consultar histórico das assinaturas e eventos do Stripe',
        'plans.manage' => 'Configurar planos (preço, serviços incluídos, versões)',
        // Comunicacao e avaliacoes (Fase 10). Campanha e marketing: criar
        // rascunho nao da direito a disparar; ver o registro de e-mails nao da
        // direito a reenviar nem a configurar.
        'communications.view' => 'Ver o registro de e-mails (endereço mascarado) e pré-visualizar os modelos',
        'communications.retry' => 'Reenviar e-mail que falhou',
        'communications.settings' => 'Configurar lembretes, pedido de avaliação e ritmo das campanhas',
        'campaigns.view' => 'Ver campanhas e seus resultados',
        'campaigns.manage' => 'Criar e editar rascunho de campanha e enviar teste para a equipe',
        'campaigns.send' => 'Disparar e cancelar campanha',
        'reviews.view' => 'Ver todas as avaliações (inclusive as aguardando revisão)',
        'reviews.view_own' => 'Ver as avaliações publicadas dos próprios atendimentos',
        'reviews.moderate' => 'Aprovar, recusar (com motivo) e destacar avaliações',
        'reviews.reply' => 'Responder avaliações em nome da barbearia',
        // Configuracoes gerais (fases seguintes)
        'settings.manage' => 'Alterar configurações do estabelecimento',
    ],

    'customer_abilities' => [
        'account.access' => 'Acessar a própria conta de cliente',
    ],

    'roles' => [
        // Proprietario: administracao completa, habilidade por habilidade.
        'owner' => [
            'panel.access', 'agenda.view', 'system.health.view',
            'users.manage', 'audit.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.view_cpf', 'customers.anonymize',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'schedule.settings', 'schedule.working_hours', 'schedule.time_off', 'schedule.blocks',
            'services.view', 'services.create', 'services.update', 'services.toggle', 'services.price', 'services.display',
            'professionals.view', 'professionals.create', 'professionals.update', 'professionals.toggle', 'professionals.services', 'professionals.display',
            'attendances.view', 'attendances.manage', 'attendances.discount', 'attendances.cancel',
            'payments.receive', 'payments.refund', 'cash.view', 'cash.open', 'cash.move', 'cash.close',
            'products.view', 'products.create', 'products.update', 'products.toggle',
            'stock.view', 'stock.receive', 'stock.issue', 'stock.adjust',
            'commissions.view', 'commissions.configure', 'commissions.correct', 'commissions.history',
            'payouts.view', 'payouts.create', 'payouts.reverse', 'advances.create', 'advances.reverse',
            'reports.view',
            'coupons.view', 'coupons.manage', 'promotions.apply', 'promotions.configure', 'loyalty.view', 'loyalty.adjust',
            'gift_cards.view', 'gift_cards.sell', 'gift_cards.cancel',
            'subscriptions.view', 'subscriptions.payments', 'subscriptions.create', 'subscriptions.cancel', 'subscriptions.reactivate',
            'subscriptions.refund', 'subscriptions.history', 'plans.manage',
            'communications.view', 'communications.retry', 'communications.settings',
            'campaigns.view', 'campaigns.manage', 'campaigns.send',
            'reviews.view', 'reviews.moderate', 'reviews.reply',
            'settings.manage',
        ],

        // Gerente: opera a barbearia; nao mexe em usuarios, auditoria,
        // configuracoes, lancamentos financeiros (inclusive estorno, repasse,
        // vale e regra de comissao) nem LGPD. Consulta comissoes e repasses.
        'manager' => [
            'panel.access', 'agenda.view', 'system.health.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.view_cpf',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'schedule.settings', 'schedule.working_hours', 'schedule.time_off', 'schedule.blocks',
            'services.view', 'services.create', 'services.update', 'services.toggle', 'services.price', 'services.display',
            'professionals.view', 'professionals.create', 'professionals.update', 'professionals.toggle', 'professionals.services', 'professionals.display',
            'attendances.view', 'attendances.manage', 'attendances.discount', 'attendances.cancel',
            'payments.receive', 'cash.view', 'cash.open', 'cash.move', 'cash.close',
            'products.view', 'products.create', 'products.update', 'products.toggle',
            'stock.view', 'stock.receive', 'stock.issue', 'stock.adjust',
            'commissions.view', 'commissions.history', 'payouts.view',
            'reports.view',
            'coupons.view', 'coupons.manage', 'promotions.apply', 'loyalty.view', 'loyalty.adjust',
            'gift_cards.view', 'gift_cards.sell',
            'subscriptions.view', 'subscriptions.payments', 'subscriptions.create', 'subscriptions.cancel', 'subscriptions.reactivate', 'subscriptions.history',
            'communications.view', 'communications.retry',
            'campaigns.view', 'campaigns.manage', 'campaigns.send',
            'reviews.view', 'reviews.moderate', 'reviews.reply',
        ],

        // Recepcao: agenda, clientes, atendimento e caixa do dia. Catalogo,
        // equipe e produtos so para consulta; estoque: entrada e saida, sem
        // ajuste de inventario. Sem desconto manual nem estorno.
        'reception' => [
            'panel.access', 'agenda.view',
            'customers.view', 'customers.create', 'customers.update',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'schedule.time_off', 'schedule.blocks',
            'services.view', 'professionals.view',
            'attendances.view', 'attendances.manage', 'attendances.cancel',
            'payments.receive', 'cash.view', 'cash.open', 'cash.move', 'cash.close',
            'products.view', 'stock.view', 'stock.receive', 'stock.issue',
            'coupons.view', 'promotions.apply', 'loyalty.view', 'gift_cards.view', 'gift_cards.sell',
            'subscriptions.view', 'subscriptions.create',
            'reviews.view',
        ],

        // Financeiro: numeros, sem agenda e sem clientes. Consulta
        // atendimentos, caixa e estoque; estorna pagamentos; paga e corrige
        // comissoes, repasses e vales. Regra de comissao: so o proprietario.
        'finance' => [
            'panel.access',
            'attendances.view', 'payments.refund', 'cash.view',
            'products.view', 'stock.view',
            'commissions.view', 'commissions.correct', 'commissions.history',
            'payouts.view', 'payouts.create', 'payouts.reverse', 'advances.create', 'advances.reverse',
            'reports.view',
            'coupons.view', 'loyalty.view', 'gift_cards.view', 'gift_cards.cancel',
            'subscriptions.view', 'subscriptions.payments', 'subscriptions.refund', 'subscriptions.history',
        ],

        // Profissional: so o que e dele (Policies conferem o registro).
        // Conclui o proprio atendimento registrando o pagamento, sem ver nem
        // operar o caixa e sem estoque.
        'professional' => [
            'panel.access', 'agenda.view',
            'customers.view_own',
            'appointments.view_own', 'appointments.manage_own',
            'attendances.view_own', 'attendances.manage_own', 'payments.receive',
            'commissions.view_own', 'promotions.apply',
            'reviews.view_own',
        ],
    ],

];

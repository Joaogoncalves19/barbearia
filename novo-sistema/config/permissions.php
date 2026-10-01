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

        // Financeiro (Fase 7)
        'finance.view' => 'Ver o financeiro',
        'finance.manage' => 'Lançar despesas, vales e pagar comissões',
        'reports.view' => 'Ver relatórios',

        // Marketing e configuracoes (Fases 8 a 10)
        'marketing.manage' => 'Gerenciar cupons, campanhas e fidelidade',
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
            'finance.view', 'finance.manage', 'reports.view',
            'marketing.manage', 'settings.manage',
        ],

        // Gerente: opera a barbearia; nao mexe em usuarios, auditoria,
        // configuracoes, lancamentos financeiros (inclusive estorno) nem LGPD.
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
            'finance.view', 'reports.view',
            'marketing.manage',
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
        ],

        // Financeiro: numeros, sem agenda e sem clientes. Consulta
        // atendimentos, caixa e estoque; estorna pagamentos.
        'finance' => [
            'panel.access',
            'attendances.view', 'payments.refund', 'cash.view',
            'products.view', 'stock.view',
            'finance.view', 'finance.manage', 'reports.view',
        ],

        // Profissional: so o que e dele (Policies conferem o registro).
        // Conclui o proprio atendimento registrando o pagamento, sem ver nem
        // operar o caixa e sem estoque.
        'professional' => [
            'panel.access', 'agenda.view',
            'customers.view_own',
            'appointments.view_own', 'appointments.manage_own',
            'attendances.view_own', 'attendances.manage_own', 'payments.receive',
        ],
    ],

];

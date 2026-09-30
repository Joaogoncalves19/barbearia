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

        // Caixa e financeiro (Fases 6 e 7)
        'checkout.operate' => 'Fechar atendimento e lançar pagamento',
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
            'panel.access', 'system.health.view',
            'users.manage', 'audit.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.view_cpf', 'customers.anonymize',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'services.view', 'services.create', 'services.update', 'services.toggle', 'services.price', 'services.display',
            'professionals.view', 'professionals.create', 'professionals.update', 'professionals.toggle', 'professionals.services', 'professionals.display',
            'checkout.operate', 'finance.view', 'finance.manage', 'reports.view',
            'marketing.manage', 'settings.manage',
        ],

        // Gerente: opera a barbearia; nao mexe em usuarios, auditoria,
        // configuracoes, lancamentos financeiros nem LGPD.
        'manager' => [
            'panel.access', 'system.health.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.view_cpf',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'services.view', 'services.create', 'services.update', 'services.toggle', 'services.price', 'services.display',
            'professionals.view', 'professionals.create', 'professionals.update', 'professionals.toggle', 'professionals.services', 'professionals.display',
            'checkout.operate', 'finance.view', 'reports.view',
            'marketing.manage',
        ],

        // Recepcao: agenda, clientes e caixa do dia. Catalogo e equipe so
        // para consulta (precisa saber preco, duracao e quem faz o que).
        'reception' => [
            'panel.access',
            'customers.view', 'customers.create', 'customers.update',
            'appointments.view_all', 'appointments.manage', 'appointments.cancel',
            'services.view', 'professionals.view',
            'checkout.operate',
        ],

        // Financeiro: numeros, sem agenda e sem clientes.
        'finance' => [
            'panel.access',
            'finance.view', 'finance.manage', 'reports.view',
        ],

        // Profissional: so o que e dele (Policies conferem o registro).
        'professional' => [
            'panel.access',
            'customers.view_own',
            'appointments.view_own', 'appointments.manage_own',
        ],
    ],

];

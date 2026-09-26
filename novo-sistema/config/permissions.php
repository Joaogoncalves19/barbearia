<?php

/*
|--------------------------------------------------------------------------
| Matriz de permissoes da equipe (DENY BY DEFAULT)
|--------------------------------------------------------------------------
|
| - Toda habilidade precisa estar declarada em 'abilities'. Uma Gate e
|   registrada para cada uma (AppServiceProvider). Habilidade nao declarada
|   nao tem Gate e o Laravel nega.
| - Cada papel lista explicitamente o que pode. Papel ausente = nada.
| - '*' concede apenas as habilidades declaradas (nunca "qualquer coisa").
| - Acesso a dados de OUTRO usuario/cliente (acesso horizontal) e decidido
|   por Policies de cada modelo, a partir da Fase 2, alem destas Gates.
|
| Nesta fase existem so as habilidades da fundacao. Cada fase acrescenta as
| suas (ex.: 'agenda.view_all', 'finance.manage') com testes na matriz.
|
*/

return [

    'abilities' => [
        'panel.access' => 'Acessar o painel da equipe',
        'system.health.view' => 'Ver a saúde do sistema',
        'users.manage' => 'Gerenciar usuários e perfis',
    ],

    'roles' => [
        'owner' => ['*'],
        'manager' => ['panel.access', 'system.health.view'],
        'reception' => ['panel.access'],
        'finance' => ['panel.access'],
        'professional' => ['panel.access'],
    ],

];

<?php

function garantirEstruturaGestaoAdmin() {
    static $pronto = false;
    if ($pronto) {
        return;
    }

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL)");

    $colunasUsers = [];
    foreach ($pdo->query("PRAGMA table_info(users)") as $coluna) {
        $colunasUsers[$coluna['name']] = true;
    }
    if (!isset($colunasUsers['role'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'proprietario'");
    }
    if (!isset($colunasUsers['permissions'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT ''");
    }
    $pdo->exec("UPDATE users SET role = 'proprietario' WHERE role IS NULL OR role = ''");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_crm_clientes (
        cliente_id TEXT PRIMARY KEY,
        tags TEXT DEFAULT '',
        observacoes TEXT DEFAULT '',
        status_relacionamento TEXT DEFAULT 'ativo',
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_lista_espera (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id TEXT DEFAULT '',
        nome TEXT NOT NULL,
        telefone TEXT DEFAULT '',
        barbeiro_id TEXT DEFAULT '',
        data_preferida TEXT DEFAULT '',
        periodo TEXT DEFAULT 'qualquer',
        observacoes TEXT DEFAULT '',
        status TEXT DEFAULT 'aguardando',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_metas_equipe (
        barbeiro_id TEXT NOT NULL,
        mes_ano TEXT NOT NULL,
        meta_atendimentos INTEGER DEFAULT 0,
        meta_faturamento REAL DEFAULT 0,
        meta_avaliacao REAL DEFAULT 0,
        PRIMARY KEY (barbeiro_id, mes_ano)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_retencao (
        cliente_id TEXT PRIMARY KEY,
        motivo TEXT DEFAULT '',
        oferta TEXT DEFAULT '',
        status TEXT DEFAULT 'pendente',
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_conciliacao (
        referencia TEXT PRIMARY KEY,
        gateway TEXT DEFAULT '',
        status TEXT DEFAULT 'revisado',
        observacao TEXT DEFAULT '',
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_atividade (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT DEFAULT '',
        acao TEXT NOT NULL,
        detalhes TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pronto = true;
}

function obterPerfisAdmin() {
    return [
        'proprietario' => [
            'nome' => 'Proprietário',
            'descricao' => 'Acesso completo ao painel e às configurações.',
            'tabs' => ['*'],
        ],
        'gerente' => [
            'nome' => 'Gerente',
            'descricao' => 'Operação, equipe, clientes, marketing e relatórios.',
            'tabs' => ['dashboard', 'agendamentos', 'financeiro', 'relatorios', 'clientes', 'assinaturas', 'servicos', 'barbeiros', 'marketing', 'fidelidade', 'avaliacoes'],
        ],
        'recepcao' => [
            'nome' => 'Recepção',
            'descricao' => 'Agenda, clientes, espera, fidelidade e atendimento.',
            'tabs' => ['dashboard', 'agendamentos', 'clientes', 'marketing', 'fidelidade', 'avaliacoes'],
        ],
        'financeiro' => [
            'nome' => 'Financeiro',
            'descricao' => 'Financeiro, assinaturas, conciliação e relatórios.',
            'tabs' => ['dashboard', 'financeiro', 'assinaturas', 'relatorios', 'clientes'],
        ],
    ];
}

function obterPerfilAdminUsuario($username) {
    garantirEstruturaGestaoAdmin();
    if ($username === '') {
        return 'proprietario';
    }
    $stmt = getDB()->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $role = (string)($stmt->fetchColumn() ?: 'proprietario');
    return array_key_exists($role, obterPerfisAdmin()) ? $role : 'proprietario';
}

function adminPodeAcessarAba($aba, $role = null) {
    // Fail-closed: o default 'proprietario' abaixo existe para admin antigo que
    // ainda nao tem role gravada -- nao para quem nao e admin. Uma sessao de
    // barbeiro nao define admin_role e herdava acesso irrestrito por aqui.
    if ($role === null && empty($_SESSION['loggedin'])) {
        return false;
    }
    $role = $role ?: ($_SESSION['admin_role'] ?? 'proprietario');
    $perfis = obterPerfisAdmin();
    $tabs = $perfis[$role]['tabs'] ?? $perfis['proprietario']['tabs'];
    return in_array('*', $tabs, true) || in_array($aba, $tabs, true);
}

function registrarAtividadeGestao($acao, $detalhes = '') {
    garantirEstruturaGestaoAdmin();
    $stmt = getDB()->prepare("INSERT INTO admin_atividade (usuario, acao, detalhes, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['username'] ?? 'sistema',
        mb_substr((string)$acao, 0, 120),
        mb_substr((string)$detalhes, 0, 500),
        date('Y-m-d H:i:s'),
    ]);
}

function valorAgendamentoGestao($agendamento, $servicosArr) {
    $total = 0.0;
    foreach (explode(',', (string)($agendamento['servicos_ids'] ?? '')) as $servicoId) {
        $servicoId = trim($servicoId);
        if ($servicoId !== '' && isset($servicosArr[$servicoId])) {
            $total += (float)($servicosArr[$servicoId]['valor'] ?? 0);
        }
    }
    $desconto = (float)($agendamento['desconto_aplicado'] ?? 0);
    return max(0, $total - $desconto);
}

function nomeServicosGestao($ids, $servicosArr) {
    $nomes = [];
    foreach (explode(',', (string)$ids) as $id) {
        $id = trim($id);
        if ($id !== '' && isset($servicosArr[$id])) {
            $nomes[] = $servicosArr[$id]['nome'];
        }
    }
    return $nomes ? implode(', ', $nomes) : 'Serviço não informado';
}

/**
 * Traduz o nome técnico de uma ação administrativa para um rótulo legível,
 * com ícone e cor, para exibição no painel de atividade recente.
 */
function descreverAtividadeAdmin($detalhes, $acao = '') {
    $slug = trim((string)$detalhes);
    if (preg_match('/Acao executada:\s*([a-z_]+)/i', $slug, $m)) {
        $slug = $m[1];
    }

    $mapa = [
        'concluir'                  => ['Atendimento concluído', 'fa-check', '#10b981'],
        'cancelar'                  => ['Agendamento cancelado', 'fa-xmark', '#ef4444'],
        'rejeitar'                  => ['Agendamento rejeitado', 'fa-ban', '#ef4444'],
        'excluir_agendamento'       => ['Agendamento excluído', 'fa-trash', '#ef4444'],
        'salvar_agendamento_manual' => ['Agendamento criado manualmente', 'fa-calendar-plus', '#0ea5e9'],
        'salvar_reagendamento'      => ['Horário reagendado', 'fa-calendar-days', '#0ea5e9'],
        'agenda_marcar_confirmacao' => ['Confirmação enviada ao cliente', 'fa-comment-dots', '#16a34a'],
        'agenda_acao_lote'          => ['Ação em lote na agenda', 'fa-layer-group', '#64748b'],
        'limpar_agendamentos_antigos' => ['Agendamentos antigos removidos', 'fa-broom', '#f59e0b'],
        'salvar_bloqueios'          => ['Bloqueio de horários salvo', 'fa-lock', '#f59e0b'],
        'acao_mes_inteiro'          => ['Agenda do mês alterada', 'fa-calendar-xmark', '#f59e0b'],
        'salvar_barbeiro'           => ['Profissional cadastrado/editado', 'fa-user-tie', '#0ea5e9'],
        'excluir_barbeiro'          => ['Profissional excluído', 'fa-user-slash', '#ef4444'],
        'salvar_semana_horarios'    => ['Grade de horários salva', 'fa-table-list', '#0ea5e9'],
        'salvar_cliente'            => ['Cliente cadastrado/editado', 'fa-user', '#0ea5e9'],
        'excluir_cliente'           => ['Cliente excluído', 'fa-user-slash', '#ef4444'],
        'toggle_cliente_status'     => ['Status de cliente alterado', 'fa-toggle-on', '#64748b'],
        'salvar_anotacao'           => ['Anotação de cliente salva', 'fa-note-sticky', '#64748b'],
        'salvar_servico'            => ['Serviço cadastrado/editado', 'fa-cut', '#0ea5e9'],
        'excluir_servico'           => ['Serviço excluído', 'fa-trash', '#ef4444'],
        'salvar_combo'              => ['Combo cadastrado/editado', 'fa-layer-group', '#0ea5e9'],
        'salvar_categoria'          => ['Categoria salva', 'fa-tags', '#0ea5e9'],
        'salvar_plano'              => ['Plano de assinatura salvo', 'fa-crown', '#7c3aed'],
        'ativar_assinatura'         => ['Assinatura ativada', 'fa-crown', '#7c3aed'],
        'salvar_produto'            => ['Produto salvo', 'fa-box', '#0ea5e9'],
        'movimentar_estoque'        => ['Estoque movimentado', 'fa-boxes-stacked', '#f59e0b'],
        'pagar_comissao'            => ['Comissão paga', 'fa-money-bill', '#10b981'],
        'salvar_vale'               => ['Vale registrado', 'fa-hand-holding-dollar', '#f59e0b'],
        'salvar_despesa'            => ['Despesa registrada', 'fa-receipt', '#ef4444'],
        'marcar_despesa_paga'       => ['Despesa marcada como paga', 'fa-circle-check', '#10b981'],
        'salvar_meta_financeira'    => ['Meta financeira atualizada', 'fa-bullseye', '#0ea5e9'],
        'enviar_newsletter'         => ['Newsletter enviada', 'fa-paper-plane', '#7c3aed'],
        'enviar_reativacao'         => ['Campanha de reativação enviada', 'fa-paper-plane', '#7c3aed'],
        'enviar_lembretes_amanha'   => ['Lembretes de amanhã enviados', 'fa-bell', '#f59e0b'],
        'enviar_lembretes_avaliacao'=> ['Lembretes de avaliação enviados', 'fa-star', '#f59e0b'],
        'gerar_voucher'             => ['Voucher gerado', 'fa-gift', '#7c3aed'],
        'salvar_cupom'              => ['Cupom salvo', 'fa-ticket', '#7c3aed'],
        'salvar_resposta_avaliacao' => ['Resposta a avaliação publicada', 'fa-reply', '#0ea5e9'],
        'toggle_destaque_avaliacao' => ['Destaque de avaliação alterado', 'fa-star', '#f59e0b'],
        'ajustar_pontos'            => ['Pontos de fidelidade ajustados', 'fa-star', '#f59e0b'],
        'salvar_landing_page'       => ['Landing page atualizada', 'fa-paint-brush', '#0ea5e9'],
        'salvar_tema'               => ['Tema visual alterado', 'fa-palette', '#0ea5e9'],
        'salvar_usuario'            => ['Usuário administrativo salvo', 'fa-user-shield', '#0ea5e9'],
        'excluir_usuario'           => ['Usuário administrativo excluído', 'fa-user-slash', '#ef4444'],
        'backup_dados'              => ['Backup gerado', 'fa-download', '#64748b'],
        'limpar_dados'              => ['Limpeza de dados executada', 'fa-triangle-exclamation', '#ef4444'],
    ];

    if (isset($mapa[$slug])) {
        return ['titulo' => $mapa[$slug][0], 'icone' => $mapa[$slug][1], 'cor' => $mapa[$slug][2]];
    }

    if (str_starts_with($slug, 'salvar_config_')) {
        return ['titulo' => 'Configuração do sistema alterada', 'icone' => 'fa-gear', 'cor' => '#64748b'];
    }
    if (str_starts_with($slug, 'excluir_')) {
        return ['titulo' => 'Registro excluído', 'icone' => 'fa-trash', 'cor' => '#ef4444'];
    }
    if (str_starts_with($slug, 'salvar_')) {
        return ['titulo' => 'Registro salvo', 'icone' => 'fa-floppy-disk', 'cor' => '#0ea5e9'];
    }

    // Sem correspondência: mostra o texto original, apenas humanizado.
    $legivel = ucfirst(str_replace('_', ' ', $slug !== '' ? $slug : (string)$acao));
    return ['titulo' => $legivel, 'icone' => 'fa-circle-info', 'cor' => '#64748b'];
}

/**
 * Lê as últimas ações administrativas registradas.
 */
function obterAtividadeRecenteAdmin($limite = 8) {
    try {
        $stmt = getDB()->prepare("SELECT usuario, acao, detalhes, created_at FROM admin_atividade ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function obterDadosGestaoAdmin($agendamentosArr, $clientesArr, $barbeirosArr, $servicosArr, $planosArr, $avaliacoesArr = [], $combosArr = [], $produtosArr = []) {
    $pdo = getDB();
    $hoje = date('Y-m-d');

    $dados = [
        'hoje' => [],
        'proximos_dias' => [],
        'espera' => [],
        'crm' => [],
        'assinaturas' => [],
        'retencao' => [],
        'conciliacao' => [],
        'equipe' => [],
        'atividades' => [],
        'alertas' => [],
        'busca' => [],
        'grafico' => [],
        'metricas' => [
            'agendamentos_hoje' => 0,
            'concluidos_hoje' => 0,
            'faturamento_mes' => 0,
            'ticket_medio' => 0,
            'ocupacao_hoje' => 0,
            'cancelamentos_mes' => 0,
            'assinantes_ativos' => 0,
            'receita_recorrente' => 0,
        ],
    ];

    // Esta função hoje só alimenta o sino de alertas e a busca global do
    // topo do painel (as demais chaves acima existem por compatibilidade
    // com o antigo dashboard "gestão" multi-abas, já removido). Por isso
    // calculamos aqui só o mínimo necessário para essas duas coisas, em
    // vez de recalcular métricas/relatórios inteiros a cada carregamento.

    $dados['alertas'] = obterAlertasGestaoAdmin($agendamentosArr, $clientesArr, $avaliacoesArr, $produtosArr);


    // Índice da busca global do topo. Cada item vira um resultado pesquisável
    // (título/subtítulo/tipo) que leva direto à área correspondente do painel.
    // O campo 'extra' não aparece na tela: guarda telefone, e-mail e outros
    // termos que o admin costuma digitar, para casar sem poluir o subtítulo.
    $fmtValor = function ($valor) {
        return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
    };
    $soDigitos = function ($valor) {
        return preg_replace('/\D+/', '', (string)$valor);
    };

    foreach (array_slice($clientesArr, 0, 500, true) as $clienteId => $cliente) {
        $telefone = (string)($cliente['telefone'] ?? '');
        $email = (string)($cliente['email'] ?? '');
        $contato = trim($telefone . ' · ' . $email, " ·");
        $dados['busca'][] = [
            'tipo' => 'Cliente',
            'titulo' => $cliente['nome'] ?? 'Cliente',
            'subtitulo' => $contato !== '' ? $contato : 'Sem contato cadastrado',
            'url' => 'admin.php?tab=clientes&busca_cliente=' . urlencode($cliente['nome'] ?? ''),
            'icone' => 'fa-user',
            'extra' => trim($soDigitos($telefone) . ' ' . $email . ' ' . $soDigitos($cliente['cpf'] ?? '')),
        ];
    }
    $rotulosStatusBusca = [
        'pendente' => 'Pendente', 'confirmado' => 'Confirmado', 'concluido' => 'Concluído',
        'cancelado' => 'Cancelado', 'recusado' => 'Recusado', 'aguardando_pagamento' => 'Aguardando pagamento',
    ];
    foreach (array_slice($agendamentosArr, -400, 400, true) as $agendamento) {
        $dataAg = $agendamento['data'] ?? '';
        $tsAg = $dataAg ? strtotime($dataAg) : false;
        $dataFmt = $tsAg ? date('d/m/Y', $tsAg) : 's/ data';
        $status = (string)($agendamento['status'] ?? '');
        $statusLabel = $rotulosStatusBusca[$status] ?? ucfirst($status);
        $profissional = $barbeirosArr[$agendamento['barbeiro_id']]['nome'] ?? 'Profissional';
        $dados['busca'][] = [
            'tipo' => 'Agendamento',
            'titulo' => ($agendamento['nome'] ?? 'Cliente') . ' · ' . $dataFmt,
            'subtitulo' => trim(($agendamento['hora'] ?? '') . ' · ' . $profissional . ($statusLabel !== '' ? ' · ' . $statusLabel : ''), ' ·'),
            'url' => 'admin.php?tab=agendamentos&view_mode=list&filtro_data=' . urlencode($dataAg),
            'icone' => 'fa-calendar-check',
            // Casa também por data ISO, "12/03" digitado e telefone do cliente.
            'extra' => trim($dataAg . ' ' . $soDigitos($dataFmt) . ' ' . $soDigitos($agendamento['telefone'] ?? '') . ' ' . $status),
        ];
    }
    foreach ($barbeirosArr as $barbeiro) {
        $ativo = (string)($barbeiro['status'] ?? 'ativo');
        $dados['busca'][] = [
            'tipo' => 'Profissional',
            'titulo' => $barbeiro['nome'] ?? 'Profissional',
            'subtitulo' => 'Equipe · ' . ($ativo === 'inativo' ? 'inativo' : 'ativo'),
            'url' => 'admin.php?tab=barbeiros',
            'icone' => 'fa-user-tie',
            'extra' => (string)($barbeiro['username'] ?? ''),
        ];
    }
    foreach ($servicosArr as $servico) {
        $dados['busca'][] = [
            'tipo' => 'Serviço',
            'titulo' => $servico['nome'] ?? 'Serviço',
            'subtitulo' => $fmtValor($servico['valor'] ?? 0),
            'url' => 'admin.php?tab=servicos&subtab=servicos-individuais',
            'icone' => 'fa-scissors',
            'extra' => (string)($servico['descricao'] ?? ''),
        ];
    }
    foreach ($combosArr as $combo) {
        $dados['busca'][] = [
            'tipo' => 'Combo',
            'titulo' => $combo['nome'] ?? 'Combo',
            'subtitulo' => 'Pacote · ' . $fmtValor($combo['valor'] ?? 0),
            'url' => 'admin.php?tab=servicos&subtab=combos',
            'icone' => 'fa-layer-group',
            'extra' => '',
        ];
    }
    foreach ($produtosArr as $produto) {
        $estoque = (int)($produto['quantidade'] ?? 0);
        $minimo = (int)($produto['estoque_minimo'] ?? 5);
        $situacao = $estoque <= 0 ? 'esgotado' : ($estoque <= $minimo ? 'estoque baixo' : 'em estoque');
        $dados['busca'][] = [
            'tipo' => 'Produto',
            'titulo' => $produto['nome'] ?? 'Produto',
            'subtitulo' => 'Estoque: ' . $estoque . ' · ' . $fmtValor($produto['valor'] ?? 0),
            'url' => 'admin.php?tab=servicos&subtab=produtos',
            'icone' => 'fa-box',
            'extra' => $situacao,
        ];
    }
    foreach ($planosArr as $plano) {
        $dados['busca'][] = [
            'tipo' => 'Plano',
            'titulo' => $plano['nome'] ?? 'Plano',
            'subtitulo' => 'Assinatura · ' . $fmtValor($plano['valor'] ?? 0) . '/mês',
            'url' => 'admin.php?tab=servicos&subtab=planos',
            'icone' => 'fa-crown',
            'extra' => 'assinatura recorrente',
        ];
    }

    return $dados;
}

/**
 * Monta os alertas operacionais do sino do topo do painel.
 *
 * Fica separada de obterDadosGestaoAdmin() porque o painel também recarrega
 * só os alertas por AJAX (admin_alertas.php), sem precisar reconstruir o
 * índice inteiro da busca global a cada atualização.
 */
function obterAlertasGestaoAdmin($agendamentosArr, $clientesArr, $avaliacoesArr = [], $produtosArr = []) {
    $pdo = getDB();
    $hoje = date('Y-m-d');
    $alertas = [];

    // Pluraliza sem o "(s)": "1 agendamento" / "3 agendamentos".
    $plural = function ($qtd, $singular, $pluralTexto) {
        return $qtd . ' ' . ($qtd === 1 ? $singular : $pluralTexto);
    };
    $add = function ($chave, $tipo, $icone, $titulo, $descricao, $url, $contagem, $acao) use (&$alertas) {
        $alertas[] = [
            'chave'     => $chave,
            'tipo'      => $tipo,
            'icone'     => $icone,
            'titulo'    => $titulo,
            'descricao' => $descricao,
            'contagem'  => (int)$contagem,
            'url'       => $url,
            'acao'      => $acao,
        ];
    };

    // --- Agenda: pendências de hoje e dos próximos dias ---
    $pendentesHoje = 0;
    $pendentesTotais = 0;
    foreach ($agendamentosArr as $agendamento) {
        $status = $agendamento['status'] ?? '';
        if (($agendamento['data'] ?? '') === $hoje && in_array($status, ['pendente', 'aguardando_pagamento'], true)) {
            $pendentesHoje++;
        }
        if ($status === 'pendente') {
            $pendentesTotais++;
        }
    }
    if ($pendentesHoje > 0) {
        $add('agenda_hoje', 'warning', 'fa-clock',
            $plural($pendentesHoje, 'agendamento aguardando ação', 'agendamentos aguardando ação'),
            'Hoje · aprove, recuse ou confirme o pagamento',
            'admin.php?tab=agendamentos', $pendentesHoje, 'Abrir agenda');
    }
    $pendentesFuturos = max(0, $pendentesTotais - $pendentesHoje);
    if ($pendentesFuturos > 0) {
        $add('agenda_futuros', 'warning', 'fa-hourglass-half',
            $plural($pendentesFuturos, 'agendamento pendente de aprovação', 'agendamentos pendentes de aprovação'),
            'Próximos dias · aguardando sua confirmação',
            'admin.php?tab=agendamentos', $pendentesFuturos, 'Abrir agenda');
    }

    // --- Assinaturas: cancelamentos e falhas de cobrança ---
    try {
        $statusAssinaturas = $pdo->query("SELECT status, gateway_status FROM clientes_assinaturas")->fetchAll(PDO::FETCH_ASSOC);
        $cancelamentosAgendados = count(array_filter($statusAssinaturas, function ($a) {
            return ($a['status'] ?? '') === 'cancelamento_agendado';
        }));
        if ($cancelamentosAgendados > 0) {
            $add('assinaturas_cancelamento', 'danger', 'fa-heart-crack',
                $plural($cancelamentosAgendados, 'assinatura em cancelamento', 'assinaturas em cancelamento'),
                'Janela de retenção aberta · fale com o cliente',
                'admin.php?tab=assinaturas', $cancelamentosAgendados, 'Ver assinaturas');
        }
        $falhasPagamento = count(array_filter($statusAssinaturas, function ($a) {
            return in_array(strtolower((string)($a['gateway_status'] ?? '')), ['rejected', 'cancelled', 'past_due', 'paused'], true);
        }));
        if ($falhasPagamento > 0) {
            $add('assinaturas_pagamento', 'danger', 'fa-credit-card',
                $plural($falhasPagamento, 'cobrança recusada', 'cobranças recusadas'),
                'Assinaturas com pagamento em aberto no gateway',
                'admin.php?tab=assinaturas', $falhasPagamento, 'Revisar cobranças');
        }
    } catch (Exception $e) {
    }

    // --- Estoque de produtos ---
    $produtosEsgotados = 0;
    $produtosBaixos = 0;
    foreach ($produtosArr as $produto) {
        $qtd = (int)($produto['quantidade'] ?? 0);
        $minimo = (int)($produto['estoque_minimo'] ?? 5);
        if ($qtd <= 0) {
            $produtosEsgotados++;
        } elseif ($qtd <= $minimo) {
            $produtosBaixos++;
        }
    }
    if ($produtosEsgotados > 0) {
        $add('estoque_zerado', 'danger', 'fa-box-open',
            $plural($produtosEsgotados, 'produto esgotado', 'produtos esgotados'),
            'Sem saldo para venda no balcão',
            'admin.php?tab=servicos&subtab=produtos', $produtosEsgotados, 'Repor estoque');
    }
    if ($produtosBaixos > 0) {
        $add('estoque_baixo', 'warning', 'fa-boxes-stacked',
            $plural($produtosBaixos, 'produto no ponto de reposição', 'produtos no ponto de reposição'),
            'Saldo igual ou abaixo do estoque mínimo',
            'admin.php?tab=servicos&subtab=produtos', $produtosBaixos, 'Ver estoque');
    }

    // --- Despesas (vencidas / a vencer hoje) ---
    try {
        $despVencidas = 0;
        $despHoje = 0;
        $stmtDesp = $pdo->query("SELECT data_vencimento, status FROM despesas");
        foreach ($stmtDesp->fetchAll(PDO::FETCH_ASSOC) as $desp) {
            if (($desp['status'] ?? '') === 'pago') continue;
            $venc = (string)($desp['data_vencimento'] ?? '');
            if ($venc === '') continue;
            if ($venc < $hoje) {
                $despVencidas++;
            } elseif ($venc === $hoje) {
                $despHoje++;
            }
        }
        if ($despVencidas > 0) {
            $add('despesas_vencidas', 'danger', 'fa-file-invoice-dollar',
                $plural($despVencidas, 'despesa vencida em aberto', 'despesas vencidas em aberto'),
                'Contas com vencimento já passado',
                'admin.php?tab=financeiro', $despVencidas, 'Abrir financeiro');
        }
        if ($despHoje > 0) {
            $add('despesas_hoje', 'warning', 'fa-calendar-day',
                $plural($despHoje, 'despesa vence hoje', 'despesas vencem hoje'),
                'Pague ou reprograme antes do fim do dia',
                'admin.php?tab=financeiro', $despHoje, 'Abrir financeiro');
        }
    } catch (Exception $e) {
    }

    // --- Avaliações negativas ainda sem resposta ---
    try {
        $respondidas = [];
        foreach ($pdo->query("SELECT id_avaliacao FROM respostas_avaliacoes")->fetchAll(PDO::FETCH_COLUMN) as $idAv) {
            $respondidas[$idAv] = true;
        }
        $avaliacoesNegativas = 0;
        foreach ($avaliacoesArr as $av) {
            if ((int)($av['rating'] ?? 5) <= 2 && empty($respondidas[$av['id'] ?? ''])) {
                $avaliacoesNegativas++;
            }
        }
        if ($avaliacoesNegativas > 0) {
            $add('avaliacoes_negativas', 'warning', 'fa-comment-dots',
                $plural($avaliacoesNegativas, 'avaliação negativa sem resposta', 'avaliações negativas sem resposta'),
                'Notas 1 e 2 · responder reduz o impacto público',
                'admin.php?tab=avaliacoes', $avaliacoesNegativas, 'Responder');
        }
    } catch (Exception $e) {
    }

    // --- Aniversariantes do dia (relacionamento) ---
    $hojeMesDia = date('m-d');
    $aniversariantes = 0;
    foreach ($clientesArr as $cliente) {
        $nasc = (string)($cliente['data_nascimento'] ?? '');
        if ($nasc === '') continue;
        $ts = strtotime($nasc);
        if ($ts !== false && date('m-d', $ts) === $hojeMesDia) {
            $aniversariantes++;
        }
    }
    if ($aniversariantes > 0) {
        $add('aniversariantes', 'info', 'fa-cake-candles',
            $plural($aniversariantes, 'cliente faz aniversário hoje', 'clientes fazem aniversário hoje'),
            'Boa hora para enviar uma mensagem ou cupom',
            'admin.php?tab=clientes', $aniversariantes, 'Ver clientes');
    }

    // Ordena por severidade e, dentro dela, pelo volume — o item mais crítico
    // e mais volumoso aparece no topo do sino.
    $ordemSeveridade = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
    usort($alertas, function ($a, $b) use ($ordemSeveridade) {
        $sa = $ordemSeveridade[$a['tipo'] ?? 'info'] ?? 2;
        $sb = $ordemSeveridade[$b['tipo'] ?? 'info'] ?? 2;
        if ($sa !== $sb) return $sa <=> $sb;
        return ($b['contagem'] ?? 0) <=> ($a['contagem'] ?? 0);
    });

    if (!$alertas) {
        $add('tudo_em_dia', 'success', 'fa-circle-check',
            'Tudo em dia por aqui',
            'Nenhuma pendência crítica na operação agora',
            'admin.php?tab=dashboard', 0, 'Ir ao dashboard');
    }

    return $alertas;
}

/**
 * Resume os alertas para o badge do sino: quantos de cada severidade e
 * quantos exigem ação (danger + warning).
 */
function resumirAlertasGestaoAdmin(array $alertas) {
    $resumo = ['danger' => 0, 'warning' => 0, 'info' => 0, 'success' => 0, 'pendentes' => 0, 'total' => count($alertas)];
    foreach ($alertas as $alerta) {
        $tipo = $alerta['tipo'] ?? 'info';
        if (!isset($resumo[$tipo])) $tipo = 'info';
        $resumo[$tipo]++;
        if ($tipo === 'danger' || $tipo === 'warning') {
            $resumo['pendentes']++;
        }
    }
    return $resumo;
}

/**
 * Remove do sino os alertas cujo destino o perfil logado nao pode abrir.
 */
function filtrarAlertasPorPermissaoAdmin(array $alertas) {
    return array_values(array_filter($alertas, function ($alerta) {
        if (!preg_match('/tab=([a-z]+)/', (string)($alerta['url'] ?? ''), $m)) {
            return true;
        }
        return $m[1] === 'dashboard' || adminPodeAcessarAba($m[1]);
    }));
}

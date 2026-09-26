<?php
// --- AÇÕES DO MÓDULO FINANCEIRO (DRE, DESPESAS E COMISSÕES) ---

// 1. Salvar ou Atualizar Despesa
if ($action === 'salvar_despesa') {
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('desp-');
    $descricao = trim($_POST['descricao'] ?? '');
    $valor = str_replace(',', '.', $_POST['valor'] ?? '0');
    $data_vencimento = $_POST['data_vencimento'] ?? '';
    $categoria = $_POST['categoria'] ?? '';
    $status = $_POST['status'] ?? 'pendente';
    $data_pagamento = ($status === 'pago') ? date('Y-m-d') : '';
    $recorrente = !empty($_POST['recorrente']) ? 1 : 0;

    $pdo = getDB();
    if (function_exists('garantirColunasDespesas')) {
        garantirColunasDespesas();
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS despesas (id TEXT PRIMARY KEY, descricao TEXT, valor TEXT, data_vencimento TEXT, data_pagamento TEXT, status TEXT, categoria TEXT)");
    }

    // Preserva o vínculo de recorrência ao editar uma despesa existente.
    $origem = '';
    if (!empty($_POST['id'])) {
        $stmtOrig = $pdo->prepare("SELECT recorrencia_origem FROM despesas WHERE id = ?");
        $stmtOrig->execute([$id]);
        $origem = (string)($stmtOrig->fetchColumn() ?: '');
    }

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO despesas (id, descricao, valor, data_vencimento, data_pagamento, status, categoria, recorrente, recorrencia_origem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $descricao, $valor, $data_vencimento, $data_pagamento, $status, $categoria, $recorrente, $origem]);

    header('Location: admin.php?tab=financeiro&subtab=despesas&success=' . urlencode('Despesa salva com sucesso!'));
    exit;
}

// 2. Excluir Despesa
if ($action === 'excluir_despesa' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM despesas WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=financeiro&subtab=despesas&success=' . urlencode('Despesa excluída do sistema.'));
    exit;
}

// 3. Baixa Rápida de Despesa (Marcar como Paga)
if ($action === 'marcar_despesa_paga' && !empty($id)) {
    $pdo = getDB();
    $data_pagamento = date('Y-m-d');
    $stmt = $pdo->prepare("UPDATE despesas SET status = 'pago', data_pagamento = ? WHERE id = ?");
    $stmt->execute([$data_pagamento, $id]);
    header('Location: admin.php?tab=financeiro&subtab=despesas&success=' . urlencode('Despesa marcada como paga!'));
    exit;
}

// 4. Registrar Pagamento de Comissão para o Barbeiro
if ($action === 'pagar_comissao') {
    $barbeiro_id = $_POST['barbeiro_id'] ?? '';
    $mes_ano = $_POST['mes_ano'] ?? date('Y-m'); // Formato YYYY-MM
    $valor_total_servicos = str_replace(',', '.', $_POST['valor_total_servicos'] ?? '0');
    $valor_comissao = str_replace(',', '.', $_POST['valor_comissao'] ?? '0'); // já inclui a gorjeta
    $valor_gorjeta = str_replace(',', '.', $_POST['valor_gorjeta'] ?? '0');
    $data_pagamento = date('Y-m-d H:i:s');
    $id_comissao = gerarId('com-');

    if (empty($barbeiro_id)) {
        header('Location: admin.php?tab=financeiro&subtab=comissoes&error=' . urlencode('Erro: Profissional não identificado. Tente novamente.'));
        exit;
    }

    $pdo = getDB();
    // Ajustado "valor_comissao TEXT" para "valor TEXT" para corresponder à tabela real
    $pdo->exec("CREATE TABLE IF NOT EXISTS comissoes_pagas (id TEXT PRIMARY KEY, barbeiro_id TEXT, mes_ano TEXT, valor_total_servicos TEXT, valor TEXT, data_pagamento TEXT)");
    // Coluna gorjeta (repasse 100% do profissional) garantida por lib/migrations.php.

    $stmt = $pdo->prepare("INSERT INTO comissoes_pagas (id, barbeiro_id, mes_ano, valor_total_servicos, valor, gorjeta, data_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id_comissao, $barbeiro_id, $mes_ano, $valor_total_servicos, $valor_comissao, $valor_gorjeta, $data_pagamento]);

    header('Location: admin.php?tab=financeiro&subtab=comissoes&mes_financeiro='.$mes_ano.'&success=' . urlencode('Pagamento de comissão registrado com sucesso!'));
    exit;
}

// 5. Excluir/Desfazer Pagamento de Comissão
if ($action === 'excluir_pagamento_comissao' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM comissoes_pagas WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=financeiro&subtab=comissoes&success=' . urlencode('Registro de pagamento de comissão desfeito.'));
    exit;
}

// 6. Salvar Vale / Adiantamento
if ($action === 'salvar_vale') {
    $id = gerarId('vale-');
    $barbeiro_id = $_POST['barbeiro_id'] ?? '';
    $valor = str_replace(',', '.', $_POST['valor'] ?? '0');
    $data_vale = $_POST['data_vale'] ?? date('Y-m-d');
    $mes_referencia = substr($data_vale, 0, 7); // Extrai o YYYY-MM
    $descricao = trim($_POST['descricao'] ?? '');

    if (empty($barbeiro_id)) {
        header('Location: admin.php?tab=financeiro&subtab=vales&error=' . urlencode('Erro: Profissional não selecionado.'));
        exit;
    }

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS vales (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_vale TEXT, mes_referencia TEXT, descricao TEXT)");
    
    $stmt = $pdo->prepare("INSERT INTO vales (id, barbeiro_id, valor, data_vale, mes_referencia, descricao) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $barbeiro_id, $valor, $data_vale, $mes_referencia, $descricao]);
    
    header('Location: admin.php?tab=financeiro&subtab=vales&mes_financeiro='.$mes_referencia.'&success=' . urlencode('Vale/Adiantamento registrado com sucesso!'));
    exit;
}

// 7. Excluir Vale
if ($action === 'excluir_vale' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM vales WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=financeiro&subtab=vales&success=' . urlencode('Vale excluído com sucesso.'));
    exit;
}

// 8. Salvar Meta de Faturamento
if ($action === 'salvar_meta_financeira') {
    $valor = str_replace(',', '.', $_POST['meta_faturamento'] ?? '0');
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta_financeira (id INTEGER PRIMARY KEY, valor TEXT)");
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO meta_financeira (id, valor) VALUES (1, ?)");
    $stmt->execute([$valor]);
    
    header('Location: admin.php?tab=financeiro&subtab=dre&success=' . urlencode('Sua Meta de faturamento foi atualizada com sucesso!'));
    exit;
}
?>
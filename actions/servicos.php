<?php
// --- AÇÃO: MOVIMENTAR ESTOQUE (ENTRADA / SAÍDA COM LOG) ---
if ($action === 'movimentar_estoque') {
    $produto_id = $_POST['produto_id'] ?? '';
    $tipo = $_POST['tipo_movimentacao'] ?? ''; // Corrigido para corresponder ao modal
    $qtd_movimento = (int)($_POST['quantidade_mov'] ?? 0); // Corrigido para corresponder ao modal
    $motivo = trim($_POST['motivo'] ?? '');
    $data_hora = date('Y-m-d H:i:s');
    $usuario = $_SESSION['username'] ?? 'Admin';

    if ($qtd_movimento > 0 && !empty($produto_id)) {
        $pdo = getDB();
        // Garante que a tabela de logs existe
        $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)");

        // Pega a quantidade atual do produto
        $stmt = $pdo->prepare("SELECT quantidade FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prod !== false) {
            $qtd_atual = (int)$prod['quantidade'];
            $nova_qtd = ($tipo === 'entrada') ? $qtd_atual + $qtd_movimento : max(0, $qtd_atual - $qtd_movimento);

            // Atualiza o estoque do produto
            $stmtUp = $pdo->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
            $stmtUp->execute([$nova_qtd, $produto_id]);

            // Salva o histórico (log)
            $log_id = gerarId('log-');
            $stmtLog = $pdo->prepare("INSERT INTO estoque_logs (id, produto_id, tipo, quantidade, motivo, data_hora, usuario) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtLog->execute([$log_id, $produto_id, $tipo, $qtd_movimento, $motivo, $data_hora, $usuario]);
        }
    }
    
    header('Location: admin.php?tab=servicos&subtab=produtos&success=' . urlencode('Estoque movimentado e registrado com sucesso!'));
    exit;
}

// --- AÇÃO: SALVAR/EXCLUIR PRODUTO DE ESTOQUE ---
if ($action === 'salvar_produto') {
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('prod-');
    $nome = trim($_POST['nome']);
    $valor = str_replace(',', '.', $_POST['valor']);
    $quantidade = (int)$_POST['quantidade'];
    $categoria_id = $_POST['categoria_id'] ?? '';
    $estoque_minimo = max(0, (int)($_POST['estoque_minimo'] ?? 5));
    $custo = max(0, (float)str_replace(',', '.', (string)($_POST['custo'] ?? 0)));

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS produtos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, quantidade INTEGER, categoria_id TEXT)");
    // Colunas estoque_minimo/custo garantidas por lib/migrations.php (via getDB()).

    // Se for um produto novo, vamos registrar o saldo inicial no log
    $is_new = empty($_POST['id']);

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO produtos (id, nome, valor, quantidade, categoria_id, estoque_minimo, custo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $nome, $valor, $quantidade, $categoria_id, $estoque_minimo, $custo]);
    
    if ($is_new && $quantidade > 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)");
        $log_id = gerarId('log-');
        $usuario = $_SESSION['username'] ?? 'Admin';
        $data_hora = date('Y-m-d H:i:s');
        $stmtLog = $pdo->prepare("INSERT INTO estoque_logs (id, produto_id, tipo, quantidade, motivo, data_hora, usuario) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtLog->execute([$log_id, $id, 'entrada', $quantidade, 'Saldo Inicial (Cadastro)', $data_hora, $usuario]);
    }

    header('Location: admin.php?tab=servicos&subtab=produtos&success=' . urlencode('Produto salvo com sucesso no estoque!'));
    exit;
}

if ($action === 'excluir_produto' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=servicos&subtab=produtos&success=' . urlencode('Produto excluído do estoque.'));
    exit;
}

// --- AÇÃO: SALVAR/EXCLUIR CATEGORIA ---
if ($action === 'salvar_categoria') {
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('cat-');
    $nome = trim($_POST['nome']);
    $ordem = (int)($_POST['ordem'] ?? 99);
    if (!empty($nome)) {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS categorias (id TEXT PRIMARY KEY, nome TEXT, ordem INTEGER)");
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO categorias (id, nome, ordem) VALUES (?, ?, ?)");
        $stmt->execute([$id, $nome, $ordem]);
    }
    header('Location: admin.php?tab=servicos&subtab=categorias&success=' . urlencode('Categoria de serviço salva e organizada na lista!'));
    exit;
}

if ($action === 'excluir_categoria' && !empty($id)) {
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt2 = $pdo->prepare("UPDATE servicos SET categoria_id = '' WHERE categoria_id = ?");
        $stmt2->execute([$id]);
        
        $stmt3 = $pdo->prepare("UPDATE combos SET categoria_id = '' WHERE categoria_id = ?");
        $stmt3->execute([$id]);
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        log_activity("Erro ao excluir categoria: " . $e->getMessage());
    }
    
    header('Location: admin.php?tab=servicos&subtab=categorias&success=' . urlencode('Categoria apagada e desvinculada dos itens.'));
    exit;
}

// --- AÇÃO: SALVAR/EXCLUIR COMBO ---
if ($action === 'salvar_combo') { 
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('cb-'); 
    $nome = trim($_POST['nome']); 
    $servicos_ids = $_POST['combo_servicos_ids'] ?? ''; 
    $valor = str_replace(',', '.', trim($_POST['valor'])); 
    $categoria_id = $_POST['categoria_id'] ?? '';
    if (!empty($nome) && !empty($servicos_ids) && !empty($valor)) { 
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS combos (id TEXT PRIMARY KEY, nome TEXT, servicos_ids TEXT, valor TEXT, categoria_id TEXT)");
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO combos (id, nome, servicos_ids, valor, categoria_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $nome, $servicos_ids, $valor, $categoria_id]);
    } 
    header('Location: admin.php?tab=servicos&subtab=combos&success=' . urlencode('Combo promocional montado e salvo!')); 
    exit; 
}
if ($action === 'excluir_combo' && !empty($id)) { 
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM combos WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=servicos&subtab=combos&success=' . urlencode('Combo promocional removido do catálogo.')); 
    exit; 
}

// --- AÇÃO: SALVAR/EXCLUIR SERVIÇO ---
if ($action === 'salvar_servico') {
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('sv-');
    $nome = trim($_POST['nome']);
    $valor = str_replace(',', '.', trim($_POST['valor']));
    $slots = (int)($_POST['slots'] ?? 1); if ($slots <= 0) $slots = 1;
    $categoria_id = $_POST['categoria_id'] ?? '';
    $descricao = trim($_POST['descricao'] ?? '');

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS servicos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, slots INTEGER, categoria_id TEXT)");
    // Coluna descricao garantida por lib/migrations.php (via getDB()).
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO servicos (id, nome, valor, slots, categoria_id, descricao) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $nome, $valor, $slots, $categoria_id, $descricao]);
    
    header('Location: admin.php?tab=servicos&subtab=servicos-individuais&success=' . urlencode('Serviço cadastrado no sistema!'));
    exit;
}
if ($action === 'excluir_servico' && !empty($id)) { 
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM servicos WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=servicos&subtab=servicos-individuais&success=' . urlencode('Serviço apagado do sistema com sucesso.')); 
    exit; 
}

// --- AÇÕES DE PLANOS DE ASSINATURA ---
if ($action === 'salvar_plano') {
    $id = !empty($_POST['id']) ? $_POST['id'] : gerarId('plano-');
    $nome = trim($_POST['nome']);
    $valor = str_replace(',', '.', $_POST['valor']);
    $servicos = implode(',', $_POST['plano_servicos_ids'] ?? []);
    if (!empty($nome) && !empty($valor)) {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS planos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, servicos_ids TEXT)");
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO planos (id, nome, valor, servicos_ids) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $nome, $valor, $servicos]);
    }
    header('Location: admin.php?tab=servicos&subtab=planos&success=' . urlencode('Plano de assinatura criado/atualizado com sucesso!'));
    exit;
}

if ($action === 'excluir_plano' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM planos WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=servicos&subtab=planos&success=' . urlencode('Plano de assinatura excluído do catálogo.'));
    exit;
}
?>
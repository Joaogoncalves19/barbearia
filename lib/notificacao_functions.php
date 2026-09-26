<?php
// lib/notificacao_functions.php
// Contém funções para criar, ler e gerenciar as notificações internas dos clientes.

/**
 * Função interna para garantir que a tabela exista e tenha todas as colunas necessárias.
 * Executa uma migração automática caso o banco de dados seja de uma versão mais antiga.
 */
function _garantirTabelaNotificacoes() {
    $pdo = getDB();
    try {
        // Tenta criar a tabela do zero se for uma instalação nova
        $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes (
            id TEXT PRIMARY KEY,
            cliente_id TEXT,
            mensagem TEXT,
            timestamp TEXT,
            status TEXT DEFAULT 'nao_lida'
        )");
        
        // MIGRATION 1: Tenta injetar a coluna 'status' em tabelas antigas que não a possuem
        try {
            $pdo->exec("ALTER TABLE notificacoes ADD COLUMN status TEXT DEFAULT 'nao_lida'");
            // Se o ALTER TABLE funcionou, preenche os nulos como não lidos
            $pdo->exec("UPDATE notificacoes SET status = 'nao_lida' WHERE status IS NULL");
        } catch (PDOException $e) {
            // Se der erro aqui, significa que a coluna 'status' já existe
        }

        // MIGRATION 2: Tenta injetar a coluna 'timestamp' em tabelas muito antigas
        try {
            $pdo->exec("ALTER TABLE notificacoes ADD COLUMN timestamp TEXT");
            // Se funcionou, preenche as antigas com a data de hoje para não quebrar a ordem
            $agora = date('Y-m-d H:i:s');
            $pdo->exec("UPDATE notificacoes SET timestamp = '{$agora}' WHERE timestamp IS NULL");
        } catch (PDOException $e) {
            // Se der erro aqui, significa que a coluna 'timestamp' já existe
        }
        
    } catch (PDOException $e) {
        // Ignora erros genéricos de estrutura na verificação
    }
}

/**
 * ATUALIZADO (SQLite): Cria uma nova notificação.
 * @param string $cliente_id O ID do cliente.
 * @param string $mensagem A mensagem da notificação.
 */
function criarNotificacao($cliente_id, $mensagem) { 
    _garantirTabelaNotificacoes(); // Garante o banco antes de inserir
    
    $pdo = getDB();
    $id_notificacao = gerarId('notif-'); 
    $timestamp = date('Y-m-d H:i:s'); 
    $status = 'nao_lida'; 
    
    try {
        $stmt = $pdo->prepare("INSERT INTO notificacoes (id, cliente_id, mensagem, timestamp, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id_notificacao, $cliente_id, $mensagem, $timestamp, $status]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao criar notificacao para $cliente_id. Erro: " . $e->getMessage());
        }
    }
}

/**
 * ATUALIZADO (SQLite): Lê todas as notificações de um cliente, da mais nova para a mais antiga.
 * @param string $cliente_id O ID do cliente.
 * @return array
 */
function lerNotificacoesCliente($cliente_id) { 
    _garantirTabelaNotificacoes(); // Garante o banco antes de consultar
    
    $pdo = getDB();
    $notificacoes = [];

    try {
        // Busca as notificações ordenando apenas pela data/hora descrescente
        $stmt = $pdo->prepare("SELECT * FROM notificacoes WHERE cliente_id = ? ORDER BY timestamp DESC");
        $stmt->execute([$cliente_id]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notificacoes[] = [
                'id' => $row['id'],
                'cliente_id' => $row['cliente_id'],
                'mensagem' => $row['mensagem'],
                'timestamp' => $row['timestamp'],
                'status' => $row['status'] ?? 'nao_lida'
            ];
        }
    } catch (PDOException $e) {
        // Tabela pode não existir, retorna vazio
    }

    return $notificacoes;
}

/**
 * ATUALIZADO (SQLite): Marca todas as notificações de um cliente como 'lida'.
 * @param string $cliente_id O ID do cliente.
 */
function marcarNotificacoesComoLidas($cliente_id) { 
    _garantirTabelaNotificacoes(); // Garante o banco antes de atualizar
    
    $pdo = getDB();

    try {
        // Atualiza apenas as que estão 'nao_lida' para poupar processamento
        $stmt = $pdo->prepare("UPDATE notificacoes SET status = 'lida' WHERE cliente_id = ? AND status = 'nao_lida'");
        $stmt->execute([$cliente_id]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao marcar notificacoes como lidas para $cliente_id. Erro: " . $e->getMessage());
        }
    }
}
?>
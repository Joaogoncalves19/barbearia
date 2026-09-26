<?php
require_once 'functions.php';
iniciarSessaoSegura();
require_once 'functions.php';

// Apenas utilizadores logados podem avaliar
if (!isset($_SESSION['cliente_logado'])) {
    header('Location: login_cliente.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Location: cliente.php?error=csrf_invalido');
        exit;
    }

    $agendamento_id = $_POST['agendamento_id'] ?? null;
    $barbeiro_id = $_POST['barbeiro_id'] ?? null;
    $rating = $_POST['rating'] ?? null;
    $comment = trim($_POST['comment']);
    $cliente_id = $_SESSION['cliente_id'];

    if (empty($agendamento_id) || empty($barbeiro_id) || empty($rating)) {
        header('Location: cliente.php?error=avaliacao_invalida');
        exit;
    }

    // Medida de segurança: Verifica se o agendamento pertence ao cliente logado
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
    $stmt->execute([$agendamento_id]);
    $agendamento = $stmt->fetch();

    $pertence_ao_cliente = false;
    if ($agendamento) {
        if (!empty($agendamento['cliente_id'])) {
            $pertence_ao_cliente = ($agendamento['cliente_id'] === $cliente_id);
        } else {
            // Telefone da sessao lido do banco pelo ID; ambos precisam ter valor.
            $telSessao = telefoneClienteDaSessao();
            $telAg = limparTelefone($agendamento['telefone'] ?? '');
            $pertence_ao_cliente = ($telAg !== '' && $telSessao !== '' && $telAg === $telSessao);
        }
    }

    if (!$agendamento || !$pertence_ao_cliente) {
         header('Location: cliente.php?error=agendamento_nao_encontrado');
         exit;
    }

    // Salvar a avaliação no SQLite
    $id_avaliacao = gerarId('av-');
    $timestamp = date('Y-m-d H:i:s');
    $comment_safe = str_replace(["\r", "\n"], ' ', $comment); // Remove quebras de linha

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS avaliacoes (id TEXT PRIMARY KEY, agendamento_id TEXT, cliente_id TEXT, barbeiro_id TEXT, rating TEXT, comment TEXT, timestamp TEXT)");
        
        $stmtIns = $pdo->prepare("INSERT INTO avaliacoes (id, agendamento_id, cliente_id, barbeiro_id, rating, comment, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$id_avaliacao, $agendamento_id, $cliente_id, $barbeiro_id, $rating, $comment_safe, $timestamp]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("Erro ao salvar avaliação no SQLite: " . $e->getMessage());
        }
    }

    header('Location: cliente.php?success=avaliacao_enviada');
    exit;
}

// Redireciona se o acesso for direto
header('Location: cliente.php');
exit;
?>

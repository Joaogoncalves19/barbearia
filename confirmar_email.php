<?php
require_once 'functions.php';
iniciarSessaoSegura();
$configGeral = carregarConfigGeral();

$token = $_GET['token'] ?? null;
$mensagem = '';
$sucesso = false;

if (empty($token)) {
    $mensagem = "Token de confirmação inválido ou ausente.";
} else {
    try {
        $pdo = getDB();
        // Le tambem o prazo. COALESCE trata banco antigo, onde a coluna nao
        // existe ou esta zerada -- nesse caso a migration ja concedeu 48h a
        // partir da atualizacao, entao 0 aqui significa 'sem prazo registrado'.
        try {
            $stmt = $pdo->prepare("SELECT id, COALESCE(confirmation_expira_em, 0) AS expira FROM clientes WHERE confirmation_token = ? AND status = 'inativo'");
            $stmt->execute([$token]);
            $cliente = $stmt->fetch();
        } catch (Exception $e) {
            $stmt = $pdo->prepare("SELECT id, 0 AS expira FROM clientes WHERE confirmation_token = ? AND status = 'inativo'");
            $stmt->execute([$token]);
            $cliente = $stmt->fetch();
        }

        $expira = (int)($cliente['expira'] ?? 0);
        $expirado = ($cliente && $expira > 0 && $expira < time());

        if ($cliente && !$expirado) {
            $pdo->prepare("UPDATE clientes SET status = 'ativo', confirmation_token = '', confirmation_expira_em = 0 WHERE id = ?")
                ->execute([$cliente['id']]);
            $sucesso = true;
            $mensagem = "Seu cadastro foi ativado com sucesso! Você já pode acessar sua conta.";
        } elseif ($expirado) {
            // O token prova que a pessoa tem acesso ao e-mail, entao e seguro
            // habilitar o reenvio na tela de login para esta conta.
            $_SESSION['pendente_confirmacao_id'] = $cliente['id'];
            $mensagem = "Este link de confirmação expirou. Acesse a tela de login para receber um novo.";
        } else {
            $mensagem = "Este link de confirmação é inválido ou já foi utilizado.";
        }
    } catch (PDOException $e) {
        $mensagem = "Erro de conexão com o banco de dados. Tente novamente mais tarde.";
        if (function_exists('log_activity')) { log_activity("Erro no confirmar_email: " . $e->getMessage()); }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Cadastro - <?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Confirmação'; require_once 'header_app.php'; ?>

<div class="auth-solo">
    <div class="auth-solo-card">
        <?php if ($sucesso): ?>
            <div class="auth-icon-ring ok"><i class="fa fa-circle-check"></i></div>
            <h2>Cadastro Confirmado!</h2>
            <p><?= htmlspecialchars($mensagem) ?></p>
            <a href="login_cliente" class="auth-btn" style="text-decoration:none;">
                <i class="fa fa-right-to-bracket"></i> Ir para o Login
            </a>
        <?php else: ?>
            <div class="auth-icon-ring err"><i class="fa fa-triangle-exclamation"></i></div>
            <h2>Erro na Confirmação</h2>
            <p><?= htmlspecialchars($mensagem) ?></p>
            <a href="login_cliente" class="auth-btn" style="text-decoration:none;">
                <i class="fa fa-right-to-bracket"></i> Ir para o Login
            </a>
            <div class="auth-foot">
                <p style="margin-bottom:0;">Já ativou sua conta? Basta entrar. Caso contrário,
                    <a href="registro" class="auth-link">cadastre-se novamente</a>.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'chatbot_widget.php'; ?>
</body>
</html>

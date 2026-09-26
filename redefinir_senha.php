<?php
require_once 'functions.php';
iniciarSessaoSegura();
$configGeral = carregarConfigGeral();
$csrf = gerarTokenCsrf();

$mensagem = '';
$sucesso = false;

// Token vem do link (GET) ou do formulário (POST)
$token = $_POST['token'] ?? $_GET['token'] ?? '';
$email_do_token = emailDoTokenReset($token);
$token_valido = $email_do_token !== null;

if (!$token_valido && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $mensagem = "Token inválido ou expirado. Solicite um novo link de redefinição.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirma_nova_senha = $_POST['confirma_nova_senha'] ?? '';

    if (!validarTokenCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = "Sessão inválida ou expirada. Tente novamente."; $token_valido = false;
    } elseif (!$token_valido) {
        $mensagem = "Token inválido ou expirado. Solicite um novo link de redefinição.";
    } elseif ($nova_senha !== $confirma_nova_senha) {
        $mensagem = "As senhas não coincidem.";
    } elseif (!senhaAtendePolitica($nova_senha)) {
        $mensagem = mensagemPoliticaSenha();
    } else {
        try {
            $pdo = getDB();
            $stmtCli = $pdo->prepare("SELECT id FROM clientes WHERE email = ?");
            $stmtCli->execute([$email_do_token]);
            $cliente = $stmtCli->fetch();

            if ($cliente) {
                $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE clientes SET password_hash = ? WHERE id = ?")->execute([$hash, $cliente['id']]);
                revogarTokensCliente($cliente['id']);   // encerra "lembrar-me" ativos
                apagarTokenReset($token);                // token de uso único

                $sucesso = true;
                $token_valido = false;
                $mensagem = "Senha redefinida com sucesso! Você já pode fazer login.";
            } else {
                $mensagem = "Erro: cliente não encontrado.";
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao atualizar a senha no banco de dados.";
            if (function_exists('log_activity')) { log_activity("Erro ao salvar nova senha: " . $e->getMessage()); }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Redefinir Senha'; require_once 'header_app.php'; ?>

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside-content">
            <div class="auth-badge"><i class="fa fa-key"></i></div>
            <h1>Criar Nova Senha</h1>
            <p class="auth-aside-lead">Você está a um passo de recuperar o acesso. Escolha uma senha forte.</p>
            <ul class="auth-features">
                <li><i class="fa fa-shield-halved"></i> Use letras, números e símbolos</li>
                <li><i class="fa fa-circle-check"></i> Mínimo de 8 caracteres</li>
                <li><i class="fa fa-user-shield"></i> Protege todos os seus dados</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-head">
                <h2>Redefinir Senha</h2>
                <p>Crie uma nova senha para a sua conta.</p>
            </div>

            <?php if ($mensagem): ?>
                <div class="auth-alert <?= $sucesso ? 'is-success' : 'is-error' ?>" role="alert">
                    <i class="fa <?= $sucesso ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                    <span><?= htmlspecialchars($mensagem) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <a href="login_cliente" class="auth-btn" style="text-decoration:none;">
                    <i class="fa fa-right-to-bracket"></i> Ir para o Login
                </a>
            <?php elseif ($token_valido): ?>
            <form method="POST" data-spinner>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="auth-field">
                    <label class="auth-label" for="nova_senha">Nova Senha</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input has-toggle" type="password" name="nova_senha" id="nova_senha"
                               minlength="8" required autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                        <i class="fa fa-lock auth-ficon"></i>
                        <button type="button" class="auth-toggle" data-toggle-password="nova_senha" aria-label="Mostrar senha">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <div class="auth-strength" data-strength="nova_senha" data-score="0">
                        <div class="auth-strength-bars"><span></span><span></span><span></span><span></span></div>
                        <div class="auth-strength-label">Força: <b>—</b></div>
                    </div>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="confirma_nova_senha">Confirme a Nova Senha</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input has-toggle" type="password" name="confirma_nova_senha" id="confirma_nova_senha"
                               required autocomplete="new-password" placeholder="Repita a nova senha"
                               data-match="nova_senha" data-match-hint="matchHint">
                        <i class="fa fa-circle-check auth-ficon"></i>
                        <button type="button" class="auth-toggle" data-toggle-password="confirma_nova_senha" aria-label="Mostrar senha">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <div class="auth-hint" id="matchHint"></div>
                </div>

                <button type="submit" class="auth-btn" data-loading-text="Salvando...">
                    <i class="fa fa-floppy-disk"></i> Salvar Nova Senha
                </button>
            </form>
            <?php else: ?>
                <a href="esqueci_senha" class="auth-btn" style="text-decoration:none;">
                    <i class="fa fa-rotate-right"></i> Solicitar novo link
                </a>
            <?php endif; ?>

            <div class="auth-foot">
                <a href="login_cliente" class="auth-back"><i class="fa fa-arrow-left"></i> Voltar para o Login</a>
            </div>
        </div>
    </main>
</div>

<script src="<?= assetUrl('js/auth.js') ?>"></script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

<?php
require_once 'functions.php';
iniciarSessaoSegura();
$configGeral = carregarConfigGeral();
$csrf = gerarTokenCsrf();

$mensagem = '';
$msg_tipo = '';

// Mensagem neutra padrão (nunca revela se o e-mail existe — anti-enumeração)
$MSG_NEUTRA = "Se o e-mail estiver cadastrado, você receberá um link de recuperação em instantes.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!validarTokenCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = "Sessão inválida ou expirada. Recarregue a página e tente novamente."; $msg_tipo = 'erro';
    } elseif (empty($email)) {
        $mensagem = "Por favor, informe seu e-mail."; $msg_tipo = 'erro';
    } elseif (!empty($_SESSION['reset_cooldown']) && time() < $_SESSION['reset_cooldown']) {
        // Anti-spam leve: 1 solicitação por minuto por sessão.
        $mensagem = $MSG_NEUTRA; $msg_tipo = 'sucesso';
    } elseif (resetSenhaBloqueadoAte($email) > 0) {
        // Limite persistente (por e-mail alvo e por IP), guardado no banco.
        // O cooldown de sessão acima sozinho não segurava nada: bastava
        // descartar o cookie a cada request para usar o SMTP da barbearia
        // como mail bomb contra a caixa de entrada de um cliente.
        //
        // A mensagem continua sendo a NEUTRA: se o bloqueio respondesse
        // diferente, ele viraria um oráculo de "este e-mail existe" — que é
        // justamente o que o resto deste fluxo evita.
        $mensagem = $MSG_NEUTRA; $msg_tipo = 'sucesso';
    } else {
        $_SESSION['reset_cooldown'] = time() + 60;
        registrarPedidoResetSenha($email);
        $cliente = getClientePorEmailTelefoneOuCPF($email);

        if ($cliente && !empty($cliente['email'])) {
            try {
                $rawToken = criarTokenResetSenha($cliente['email']);
                $link = BASE_URL . "redefinir_senha?token=" . urlencode($rawToken);
                $dados_email = ['link_redefinicao' => $link, 'nome_cliente' => $cliente['nome']];
                enviarEmail($cliente['email'], "Redefinir Senha - " . $configGeral['nome_barbearia'], 'redefinir_senha_link', $dados_email);
            } catch (Exception $e) {
                if (function_exists('log_activity')) { log_activity("Erro no fluxo de reset: " . $e->getMessage()); }
            }
        }
        // Resposta idêntica exista ou não o e-mail
        $mensagem = $MSG_NEUTRA; $msg_tipo = 'sucesso';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Senha - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Recuperar Senha'; require_once 'header_app.php'; ?>

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside-content">
            <div class="auth-badge"><i class="fa fa-unlock-keyhole"></i></div>
            <h1>Recuperação de Acesso</h1>
            <p class="auth-aside-lead">Sem problemas. Enviaremos um link seguro para você criar uma nova senha.</p>
            <ul class="auth-features">
                <li><i class="fa fa-shield-halved"></i> Link válido por apenas 1 hora</li>
                <li><i class="fa fa-envelope-circle-check"></i> Enviado direto ao seu e-mail</li>
                <li><i class="fa fa-lock"></i> Suas sessões antigas são encerradas</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-head">
                <h2>Esqueceu a Senha?</h2>
                <p>Digite seu e-mail e enviaremos um link para redefinir sua senha.</p>
            </div>

            <?php if ($mensagem): ?>
                <div class="auth-alert <?= $msg_tipo === 'erro' ? 'is-error' : 'is-success' ?>" role="alert">
                    <i class="fa <?= $msg_tipo === 'erro' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                    <span><?= htmlspecialchars($mensagem) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" data-spinner>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="auth-field">
                    <label class="auth-label" for="email">Seu E-mail Cadastrado</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input" type="email" name="email" id="email" required
                               autocomplete="email" placeholder="exemplo@email.com" autofocus>
                        <i class="fa fa-envelope auth-ficon"></i>
                    </div>
                </div>

                <button type="submit" class="auth-btn" data-loading-text="Enviando...">
                    <i class="fa fa-paper-plane"></i> Enviar Link de Recuperação
                </button>
            </form>

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

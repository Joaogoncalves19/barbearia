<?php
require_once 'functions.php';
iniciarSessaoSegura();
$configGeral = carregarConfigGeral();

// Token CSRF compartilhado
$csrf = gerarTokenCsrf();

// --- LÓGICA DO "LEMBRAR-ME" (AUTO-LOGIN VIA COOKIE) ---
if (!isset($_SESSION['cliente_logado']) && isset($_COOKIE['lembrar_cliente'])) {
    try {
        $cliente_cookie = autenticarClientePorCookieLembrar($_COOKIE['lembrar_cliente']);
        if ($cliente_cookie) {
            session_regenerate_id(true);
            $_SESSION['cliente_logado'] = true;
            $_SESSION['cliente_id'] = $cliente_cookie['id'];
            $_SESSION['cliente_nome'] = $cliente_cookie['nome'];
            $_SESSION['cliente_email'] = $cliente_cookie['email'];
            $_SESSION['cliente_telefone'] = $cliente_cookie['telefone'];

            $redirect = $_GET['redirect'] ?? 'cliente';
            if (!in_array($redirect, ['agendamento', 'cliente'], true)) {
                $redirect = 'cliente';
            }
            header('Location: ' . $redirect);
            exit;
        }
        apagarCookieSeguro('lembrar_cliente');
    } catch (Exception $e) {
        // Falha silenciosa no auto-login
    }
}

$mensagem = '';
$msg_sucesso = false;
$conta_inativa = false; // controla a exibição do botão "reenviar confirmação"

// Mensagens vindas de outros fluxos (via querystring)
if (isset($_GET['success'])) {
    if ($_GET['success'] === '1') { $mensagem = "Cadastro realizado com sucesso! Faça o login."; $msg_sucesso = true; }
    if ($_GET['success'] === 'confirm_email') { $mensagem = "Cadastro realizado! Verifique seu e-mail para ativar sua conta."; $msg_sucesso = true; }
}
if (($_GET['reset'] ?? '') === 'success') { $mensagem = "Sua senha foi redefinida com sucesso! Você já pode fazer o login."; $msg_sucesso = true; }
if (($_GET['activated'] ?? '') === '1') { $mensagem = "Sua conta foi ativada com sucesso! Faça o login."; $msg_sucesso = true; }
if (($_GET['error'] ?? '') === 'inactive') {
    $mensagem = "Sua conta ainda não foi ativada. Verifique seu e-mail de confirmação."; $msg_sucesso = false;
}

$acao = $_POST['acao'] ?? '';

// --- REENVIO DE E-MAIL DE CONFIRMAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'reenviar_confirmacao') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = "Sessão inválida. Recarregue a página e tente novamente."; $msg_sucesso = false;
    } elseif (($bloqueioReenvio = reenvioConfirmacaoBloqueadoAte($_SESSION['pendente_confirmacao_id'] ?? '')) > 0) {
        // Limite por CLIENTE: sem ele, quem tivesse o id pendente na sessao podia
        // usar o SMTP da barbearia para encher a caixa de entrada de outra pessoa.
        $minutos = max(1, ceil(($bloqueioReenvio - time()) / 60));
        $mensagem = "Já enviamos vários e-mails de confirmação. Aguarde {$minutos} minuto(s) e verifique também o spam.";
        $msg_sucesso = false; $conta_inativa = true;
    } elseif (!empty($_SESSION['pendente_confirmacao_id'])) {
        try {
            $stmt = getDB()->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['pendente_confirmacao_id']]);
            $pend = $stmt->fetch();
            // Contabiliza ANTES do envio: um SMTP lento nao pode virar brecha.
            if ($pend) { registrarReenvioConfirmacao($pend['id']); }
            if ($pend && reenviarConfirmacaoCadastro($pend)) {
                $mensagem = "Enviamos um novo e-mail de confirmação. Verifique sua caixa de entrada (e spam)."; $msg_sucesso = true;
                unset($_SESSION['pendente_confirmacao_id']);
            } else {
                $mensagem = "Não foi possível reenviar agora. Tente novamente em instantes."; $msg_sucesso = false; $conta_inativa = true;
            }
        } catch (Exception $e) {
            $mensagem = "Não foi possível reenviar agora. Tente novamente em instantes."; $msg_sucesso = false; $conta_inativa = true;
        }
    }
}

// --- LOGIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao !== 'reenviar_confirmacao') {
    $identificador = trim($_POST['identificador'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $lembrar = isset($_POST['lembrar']);

    if (!validarTokenCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = "Sessão inválida ou expirada. Por favor, tente novamente.";
    } elseif (($bloqueio = loginBloqueadoAte($identificador)) > 0) {
        $minutos = max(1, ceil(($bloqueio - time()) / 60));
        $mensagem = "Muitas tentativas. Tente novamente em {$minutos} minuto(s).";
    } elseif (empty($identificador) || empty($senha)) {
        $mensagem = "Os dados de acesso e a senha são obrigatórios.";
    } else {
        try {
            // Usa o localizador compartilhado em vez de uma consulta propria.
            // A copia que existia aqui nao tinha guarda de vazio: como
            // limparTelefone('um@email.com') devolve '', um e-mail inexistente
            // casava com qualquer cliente de telefone ou CPF vazio (CPF e
            // opcional no cadastro) e a senha era conferida contra a conta errada.
            $cliente = getClientePorEmailTelefoneOuCPF($identificador);

            if ($cliente && password_verify($senha, $cliente['password_hash'])) {
                if (($cliente['status'] ?? '') === 'ativo') {
                    session_regenerate_id(true);
                    limparFalhasLogin($identificador);

                    $_SESSION['cliente_logado'] = true;
                    $_SESSION['cliente_id'] = $cliente['id'];
                    $_SESSION['cliente_nome'] = $cliente['nome'];
                    $_SESSION['cliente_email'] = $cliente['email'];
                    $_SESSION['cliente_telefone'] = $cliente['telefone'];

                    if ($lembrar) {
                        criarCookieLembrarCliente($cliente['id'], 30);
                        definirCookieSeguro('salvar_identificador', $identificador, time() + (86400 * 30), false);
                    } else {
                        revogarCookieLembrarClienteAtual();
                        apagarCookieSeguro('salvar_identificador');
                    }

                    $redirect = $_POST['redirect'] ?? 'cliente';
                    if (!in_array($redirect, ['agendamento', 'cliente'], true)) {
                        $redirect = 'cliente';
                    }
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // Credenciais corretas, mas conta inativa: habilita reenvio seguro
                    $_SESSION['pendente_confirmacao_id'] = $cliente['id'];
                    $conta_inativa = true;
                    $mensagem = "Sua conta ainda não foi ativada. Confirme seu e-mail para acessar.";
                }
            } else {
                $restam = registrarFalhaLogin($identificador);
                $mensagem = $restam > 0
                    ? "Credenciais incorretas. Tentativas restantes: {$restam}."
                    : "Muitas tentativas falhas. Acesso temporariamente bloqueado por segurança.";
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao conectar com o banco de dados.";
            if (function_exists('log_activity')) { log_activity("Erro no login SQLite: " . $e->getMessage()); }
        }
    }
}

$identificador_salvo = $_COOKIE['salvar_identificador'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login do Cliente - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Login'; require_once 'header_app.php'; ?>

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside-content">
            <div class="auth-badge"><i class="fa fa-scissors"></i></div>
            <h1>Bem-vindo de volta!</h1>
            <p class="auth-aside-lead">Acesse sua conta para gerenciar agendamentos e aproveitar seus benefícios.</p>
            <ul class="auth-features">
                <li><i class="fa fa-calendar-check"></i> Agende em segundos, quando quiser</li>
                <li><i class="fa fa-star"></i> Acompanhe seus pontos de fidelidade</li>
                <li><i class="fa fa-clock-rotate-left"></i> Veja seu histórico completo</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-head">
                <h2>Área do Cliente</h2>
                <p>Informe suas credenciais para acessar.</p>
            </div>

            <?php if ($mensagem): ?>
                <div class="auth-alert <?= $msg_sucesso ? 'is-success' : 'is-error' ?>" role="alert">
                    <i class="fa <?= $msg_sucesso ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                    <span><?= htmlspecialchars($mensagem) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($conta_inativa): ?>
                <form method="POST" style="margin-bottom:22px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="acao" value="reenviar_confirmacao">
                    <button type="submit" class="auth-btn-ghost">
                        <i class="fa fa-paper-plane"></i> Reenviar e-mail de confirmação
                    </button>
                </form>
            <?php endif; ?>

            <form method="POST" id="formLogin" data-spinner>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">

                <div class="auth-field">
                    <label class="auth-label" for="identificador">E-mail, CPF ou Telefone</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input" type="text" name="identificador" id="identificador"
                               placeholder="Seu acesso..." autocomplete="username"
                               value="<?= htmlspecialchars($identificador_salvo) ?>" required
                               <?= empty($identificador_salvo) ? 'autofocus' : '' ?>>
                        <i class="fa fa-user auth-ficon"></i>
                    </div>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="senha">Senha</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input has-toggle" type="password" name="senha" id="senha"
                               placeholder="••••••••" autocomplete="current-password" required
                               <?= !empty($identificador_salvo) ? 'autofocus' : '' ?>>
                        <i class="fa fa-lock auth-ficon"></i>
                        <button type="button" class="auth-toggle" data-toggle-password="senha" aria-label="Mostrar senha">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="auth-options">
                    <label class="auth-check">
                        <input type="checkbox" name="lembrar" <?= !empty($identificador_salvo) ? 'checked' : '' ?>> Lembrar-me
                    </label>
                    <a href="esqueci_senha" class="auth-link">Esqueceu a senha?</a>
                </div>

                <button type="submit" class="auth-btn" data-loading-text="Acessando...">
                    <i class="fa fa-right-to-bracket"></i> Entrar na Conta
                </button>
            </form>

            <div class="auth-foot">
                <p>Ainda não é cliente?</p>
                <a href="registro" class="auth-btn-ghost"><i class="fa fa-user-plus"></i> Criar nova conta</a>
            </div>
        </div>
    </main>
</div>

<script src="<?= assetUrl('js/auth.js') ?>"></script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

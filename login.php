<?php
// Importa o arquivo de funções PRIMEIRO para aplicar as configurações de segurança do cookie
require_once 'functions.php';

if (session_status() === PHP_SESSION_NONE) {
    iniciarSessaoSegura();
}

$configGeral = carregarConfigGeral();
$mensagemErro = '';

// Proteção contra Força Bruta (Rate Limiting via Banco de Dados/IP)
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 300); // 5 minutos de bloqueio

$ip_usuario = $_SERVER['REMOTE_ADDR'];
$pdo = getDB();
garantirEstruturaGestaoAdmin();

// Cria a tabela de tentativas caso não exista
$pdo->exec("CREATE TABLE IF NOT EXISTS sys_login_attempts (ip_address TEXT PRIMARY KEY, attempts INTEGER, last_attempt INTEGER)");

// Verifica o status de bloqueio do IP atual
$stmtIp = $pdo->prepare("SELECT attempts, last_attempt FROM sys_login_attempts WHERE ip_address = ?");
$stmtIp->execute([$ip_usuario]);
$rateLimit = $stmtIp->fetch(PDO::FETCH_ASSOC);

$bloqueado = false;
if ($rateLimit && $rateLimit['attempts'] >= MAX_LOGIN_ATTEMPTS) {
    $tempo_passado = time() - $rateLimit['last_attempt'];
    if ($tempo_passado < LOGIN_LOCKOUT_TIME) {
        $wait_time = ceil((LOGIN_LOCKOUT_TIME - $tempo_passado) / 60);
        $mensagemErro = "Muitas tentativas falhas de acesso. Por segurança, o login foi bloqueado. Tente novamente em {$wait_time} minuto(s).";
        $bloqueado = true;
        $_SERVER['REQUEST_METHOD'] = 'GET'; // Impede o processamento
    } else {
        // Libera após o tempo de bloqueio expirar
        $stmtReset = $pdo->prepare("UPDATE sys_login_attempts SET attempts = 0 WHERE ip_address = ?");
        $stmtReset->execute([$ip_usuario]);
        $rateLimit['attempts'] = 0;
    }
}

// Gera o token de segurança para o formulário
$csrf_token = generate_csrf_token();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
    // Validação estrita do CSRF Token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $mensagemErro = 'Sessão expirada ou requisição inválida. Por favor, recarregue a página.';
    } else {
        $login_type = $_POST['login_type'] ?? 'admin';
        $username_input = trim($_POST['username']);
        $password_input = $_POST['password'];

        $loginSuccess = false;

        if ($login_type === 'admin') {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL)");

                // MODIFICADO: Utiliza LOWER() para ignorar maiúsculas/minúsculas no nome de usuário
                $stmt = $pdo->prepare("SELECT username, password_hash, role FROM users WHERE LOWER(username) = LOWER(?)");
                $stmt->execute([$username_input]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password_input, $user['password_hash'])) {
                    session_regenerate_id(true); // Previne fixação de sessão
                    $_SESSION['loggedin'] = true;
                    $_SESSION['admin_role'] = $user['role'] ?: 'proprietario';
                    $_SESSION['username'] = $user['username']; // Pega o usuário com as maiúsculas/minúsculas corretas do banco
                    $loginSuccess = true;
                }
            } catch (PDOException $e) {
                 if (function_exists('log_activity')) {
                     log_activity("FALHA: Erro ao ler users do SQLite (login.php). Erro: " . $e->getMessage());
                 }
                 $mensagemErro = 'Erro interno do servidor. Tente novamente.';
            }
        } elseif ($login_type === 'barbeiro') {
            $barbeiro = getBarbeiroByUsername($username_input); 
            
            // MODIFICADO: Fallback de busca ignorando maiúsculas e minúsculas caso a função não encontre
            if (!$barbeiro) {
                try {
                    $stmtB = $pdo->prepare("SELECT * FROM barbeiros WHERE LOWER(email) = LOWER(?) OR LOWER(nome) = LOWER(?)");
                    $stmtB->execute([$username_input, $username_input]);
                    $barbeiro = $stmtB->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {}
            }
            
            // Corrigido para validar a chave 'password' retornada do banco
            if ($barbeiro && password_verify($password_input, $barbeiro['password'] ?? '')) {
                // Bloqueia acesso de profissionais desativados (mesmo critério
                // que já os exclui da agenda pública em assistente.php).
                if (($barbeiro['status'] ?? 'ativo') === 'inativo') {
                    $mensagemErro = 'Este acesso está desativado. Fale com a administração.';
                } else {
                    session_regenerate_id(true); // Previne fixação de sessão
                    $_SESSION['barbeiro_loggedin'] = true;
                    $_SESSION['barbeiro_id'] = $barbeiro['id'];
                    $_SESSION['barbeiro_nome'] = $barbeiro['nome'];
                    $loginSuccess = true;
                }
            }
        }

        // Lida com a contabilidade do Rate Limiter
        if ($loginSuccess) {
            $stmtSuccess = $pdo->prepare("UPDATE sys_login_attempts SET attempts = 0 WHERE ip_address = ?");
            $stmtSuccess->execute([$ip_usuario]);
            
            if ($login_type === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: barbeiro.php');
            }
            exit;
        } else {
            if (empty($mensagemErro)) {
                 $mensagemErro = 'Usuário ou senha incorretos.';
            }
            
            // Incrementa falhas atreladas ao IP
            if ($rateLimit) {
                $stmtFail = $pdo->prepare("UPDATE sys_login_attempts SET attempts = attempts + 1, last_attempt = ? WHERE ip_address = ?");
                $stmtFail->execute([time(), $ip_usuario]);
            } else {
                $stmtFail = $pdo->prepare("INSERT INTO sys_login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, ?)");
                $stmtFail->execute([$ip_usuario, time()]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Área Restrita - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Acesso Restrito'; require_once 'header_app.php'; ?>

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside-content">
            <div class="auth-badge"><i class="fa fa-user-shield"></i></div>
            <h1>Painel de Controle</h1>
            <p class="auth-aside-lead">Acesso exclusivo para administradores e profissionais da equipe.</p>
            <ul class="auth-features">
                <li><i class="fa fa-calendar-days"></i> Gerencie a agenda e os agendamentos</li>
                <li><i class="fa fa-users"></i> Equipe, serviços e clientes</li>
                <li><i class="fa fa-chart-line"></i> Relatórios e desempenho</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-head">
                <h2>Área Restrita</h2>
                <p>Identifique-se para acessar o sistema.</p>
            </div>

            <?php if ($mensagemErro): ?>
                <div class="auth-alert is-error" role="alert">
                    <i class="fa fa-triangle-exclamation"></i>
                    <span><?= htmlspecialchars($mensagemErro) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="formLogin" data-spinner>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="auth-segment">
                    <input type="radio" id="type_admin" name="login_type" value="admin" checked>
                    <label for="type_admin"><i class="fa fa-user-gear"></i> Administrador</label>
                    <input type="radio" id="type_barbeiro" name="login_type" value="barbeiro">
                    <label for="type_barbeiro"><i class="fa fa-scissors"></i> Barbeiro</label>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="username">Usuário</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input" type="text" name="username" id="username"
                               placeholder="Digite seu usuário" autocomplete="username" required autofocus>
                        <i class="fa fa-user auth-ficon"></i>
                    </div>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="password">Senha</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input has-toggle" type="password" name="password" id="password"
                               placeholder="••••••••" autocomplete="current-password" required>
                        <i class="fa fa-lock auth-ficon"></i>
                        <button type="button" class="auth-toggle" data-toggle-password="password" aria-label="Mostrar senha">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-btn" data-loading-text="Autenticando...">
                    <i class="fa fa-right-to-bracket"></i> Acessar Painel
                </button>
            </form>
        </div>
    </main>
</div>

<script src="<?= assetUrl('js/auth.js') ?>"></script>
</body>
</html>

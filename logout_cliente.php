<?php
require_once 'functions.php';
iniciarSessaoSegura();

// Esvazia todas as variáveis da sessão
$_SESSION = array();

// Destrói completamente a sessão e apaga o cookie de sessão do servidor
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params["path"],
            'domain' => $params["domain"],
            'secure' => $params["secure"],
            'httponly' => $params["httponly"],
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
}

// REMOVE O COOKIE "LEMBRAR-ME" (Isso corrige o bug do auto-login imediato)
if (isset($_COOKIE['lembrar_cliente'])) {
    revogarCookieLembrarClienteAtual();
}

session_destroy();

// Redireciona para o login
header('Location: login_cliente');
exit;
?>

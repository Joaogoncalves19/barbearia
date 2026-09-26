<?php
// actions/suporte.php — envio de mensagem de suporte ao desenvolvedor.
// Normalmente é incluído por admin_actions.php (já autenticado + CSRF), mas
// como lê $_POST['action'] por conta própria, blindamos contra acesso DIRETO
// ao arquivo, que de outra forma escaparia das guardas do dispatcher.
if (!function_exists('iniciarSessaoSegura')) {
    require_once __DIR__ . '/../functions.php';
}
if (session_status() === PHP_SESSION_NONE) {
    iniciarSessaoSegura();
}

// Apenas usuários autenticados (admin ou barbeiro) podem enviar.
if (!isset($_SESSION['loggedin']) && !isset($_SESSION['barbeiro_loggedin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acesso negado.');
}
// CSRF obrigatório (constante em relação a timing via hash_equals).
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: admin.php?error=' . urlencode('Ação bloqueada por segurança (token inválido ou expirado). Tente novamente.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reportar_bug') {
    
    $tipo = htmlspecialchars($_POST['tipo_mensagem'] ?? 'Contato');
    $contato_retorno = htmlspecialchars($_POST['contato_retorno'] ?? 'Não informado');
    $mensagem = nl2br(htmlspecialchars($_POST['mensagem'] ?? ''));
    $contexto = trim($_POST['contexto'] ?? '');
    $contexto = $contexto !== '' ? nl2br(htmlspecialchars($contexto)) : '';
    $remetente_usuario = $_SESSION['username'] ?? 'Usuário Admin';
    
    $configGeral = carregarConfigGeral();
    $nomeBarbearia = $configGeral['nome_barbearia'] ?? 'Sistema Barbearia';
    
    // E-mail de destino do desenvolvedor
    $destinatario = 'john.goncalves06@gmail.com';
    $assunto = "[$tipo] Painel Admin - $nomeBarbearia";
    
    // Array de dados que será injetado no template
    $dados = [
        'nomeBarbearia'     => $nomeBarbearia,
        'remetente_usuario' => $remetente_usuario,
        'contato_retorno'   => $contato_retorno,
        'tipo'              => $tipo,
        'mensagem'          => $mensagem,
        'contexto'          => $contexto,
        'servidor'          => $_SERVER['HTTP_HOST']
    ];

    $enviado = false;

    // Chama a função corretamente com os 4 parâmetros: (Para, Assunto, Template, Dados)
    if (function_exists('enviarEmail')) {
        $enviado = enviarEmail($destinatario, $assunto, 'suporte_dev', $dados);
    } else {
        // Fallback: usa a função mail() nativa do servidor PHP caso a biblioteca falhe
        $corpoHTML = "<html><body><h2>Nova mensagem do sistema: {$dados['nomeBarbearia']}</h2><p><strong>Remetente:</strong> {$dados['remetente_usuario']}<br><strong>Contato para Retorno:</strong> {$dados['contato_retorno']}<br><strong>Tipo:</strong> {$dados['tipo']}</p><p><strong>Mensagem:</strong><br>{$dados['mensagem']}</p></body></html>";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: sistema@" . $_SERVER['HTTP_HOST'] . "\r\n";
        $enviado = mail($destinatario, $assunto, $corpoHTML, $headers);
    }

    if ($enviado) {
        header('Location: admin.php?success=' . urlencode('Sua mensagem foi enviada ao desenvolvedor com sucesso! Agradecemos o contato.'));
    } else {
        header('Location: admin.php?error=' . urlencode('Ocorreu um erro ao enviar a mensagem. Verifique a configuração de e-mail do sistema ou tente novamente mais tarde.'));
    }
    exit;
}
?>

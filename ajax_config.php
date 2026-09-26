<?php
// ajax_config.php
// Endpoints AJAX das Configurações (ex.: enviar e-mail de teste do SMTP).
require_once 'functions.php';
iniciarSessaoSegura();
header('Content-Type: application/json');

if (empty($_SESSION['loggedin'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado.']);
    exit;
}

$entrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    $entrada = $_POST;
}
$acao = $entrada['action'] ?? '';

if ($acao === 'testar_email') {
    $destino = trim((string)($entrada['destino'] ?? ''));
    $configEmail = carregarConfigEmail();
    $configGeral = carregarConfigGeral();

    // Usa os valores enviados pelo formulário (permite testar ANTES de salvar);
    // quando a senha vem vazia, reaproveita a já salva (campo mascarado).
    $host = trim((string)($entrada['host'] ?? $configEmail['host'] ?? ''));
    $username = trim((string)($entrada['username'] ?? $configEmail['username'] ?? ''));
    $password = (string)($entrada['password'] ?? '');
    if ($password === '') {
        $password = (string)($configEmail['password'] ?? '');
    }
    $port = (int)($entrada['port'] ?? $configEmail['port'] ?? 587);
    $secure = trim((string)($entrada['secure'] ?? $configEmail['smtp_secure'] ?? ''));
    $remetente = trim((string)($entrada['nome_remetente'] ?? $configGeral['nome_barbearia'] ?? 'Barbearia'));

    if ($destino === '') {
        $destino = trim((string)($configGeral['email_contato'] ?? '')) ?: $username;
    }
    if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Informe um e-mail de destino válido para o teste.']);
        exit;
    }
    if ($host === '' || $username === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Preencha Host, Usuário e Senha do SMTP antes de testar.']);
        exit;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $debugBuffer = '';
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $secure;
        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;
        $mail->SMTPDebug = 2; // captura o diálogo SMTP para diagnóstico
        $mail->Debugoutput = function ($str, $level) use (&$debugBuffer) {
            $debugBuffer .= trim($str) . "\n";
        };

        $mail->setFrom($username, $remetente);
        $mail->addAddress($destino);

        $mail->isHTML(true);
        $mail->Subject = '[TESTE] Configuração de e-mail — ' . $remetente;
        $mail->Body = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0f172a;">'
            . '<h2 style="color:#16a34a;margin:0 0 10px;">✅ E-mail de teste recebido!</h2>'
            . '<p>Se você está lendo esta mensagem, o servidor SMTP da sua barbearia está configurado corretamente.</p>'
            . '<p style="color:#64748b;font-size:13px;">Enviado em ' . date('d/m/Y H:i:s') . ' pelo painel administrativo.</p>'
            . '</div>';
        $mail->AltBody = 'E-mail de teste recebido com sucesso. SMTP configurado corretamente.';

        $mail->send();
        echo json_encode([
            'success' => true,
            'mensagem' => 'E-mail de teste enviado para ' . $destino . '. Verifique a caixa de entrada (e o spam).'
        ]);
    } catch (\Throwable $e) {
        $erro = $mail->ErrorInfo ?: $e->getMessage();
        // Extrai a última linha relevante do diálogo SMTP, se houver.
        $dica = '';
        if ($debugBuffer !== '') {
            $linhas = array_values(array_filter(array_map('trim', explode("\n", $debugBuffer))));
            $dica = $linhas ? end($linhas) : '';
        }
        echo json_encode([
            'success' => false,
            'error' => $erro,
            'detalhe' => $dica
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
exit;

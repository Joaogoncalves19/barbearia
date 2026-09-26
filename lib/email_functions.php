<?php
// lib/email_functions.php
// Contém a lógica de envio de e-mail usando a biblioteca PHPMailer.
// Integrado perfeitamente com a nova base de dados SQLite através do config_functions.php.

// Importa as classes do PHPMailer para o namespace global
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Carrega o PHPMailer. Preferimos o autoloader do Composer (vendor/) quando
// disponível; caso contrário, mantemos o carregamento manual da pasta /PHPMailer/
// (retrocompatível com o deploy atual, que não usa Composer).
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (!class_exists(PHPMailer::class)) {
    require __DIR__ . '/../PHPMailer/Exception.php';
    require __DIR__ . '/../PHPMailer/PHPMailer.php';
    require __DIR__ . '/../PHPMailer/SMTP.php';
}

/**
 * Envia um e-mail transacional usando PHPMailer.
 * Depende de: carregarConfigEmail(), carregarConfigGeral() (agora via SQLite), log_activity()
 *
 * @param string $para_email O e-mail do destinatário.
 * @param string $assunto O assunto do e-mail.
 * @param string $template O nome do arquivo de template (ex: 'confirmacao').
 * @param array $dados Um array de dados para popular o template.
 * @return bool True se o e-mail foi enviado, False caso contrário.
 */
function enviarEmail($para_email, $assunto, $template, $dados) {
    // Carrega as configurações (funções que vêm de config_functions.php - já integradas com SQLite)
    $configEmail = carregarConfigEmail();
    $configGeral = carregarConfigGeral();

    // Validação básica para evitar erros se o e-mail não estiver configurado
    if (empty($configEmail['username']) || empty($configEmail['password'])) {
        log_activity("FALHA (PHPMailer): E-mail de remetente não configurado no banco de dados.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // --- CONFIGURAÇÕES DO SERVIDOR (lidas do SQLite) ---
        $mail->isSMTP();
        $mail->Host       = $configEmail['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $configEmail['username'];
        $mail->Password   = $configEmail['password'];
        $mail->SMTPSecure = $configEmail['smtp_secure'];
        $mail->Port       = $configEmail['port'];
        $mail->CharSet    = 'UTF-8';

        //Remetente e Destinatário
        $mail->setFrom($configEmail['username'], $configGeral['nome_barbearia']);
        $mail->addAddress($para_email);
        // Reply-To usa o e-mail de contato configurado (se válido); senão, o remetente SMTP.
        $emailContato = trim((string)($configGeral['email_contato'] ?? ''));
        $replyTo = filter_var($emailContato, FILTER_VALIDATE_EMAIL) ? $emailContato : $configEmail['username'];
        $mail->addReplyTo($replyTo, $configGeral['nome_barbearia']);

        // --- LOGO DO E-MAIL (URL pública do site, não anexo) ---
        // Puxa a logo pela URL do site (ex.: https://seu-site/uploads/logo.png),
        // usando siteUrl() para funcionar também no cron/CLI (sem HTTP_HOST).
        // Só cai para anexo embutido (CID) se não houver uma URL absoluta conhecida.
        $logo_url = '';
        if (!empty($configGeral['logo_path'])) {
            $logo_rel = ltrim((string)$configGeral['logo_path'], '/');
            $logo_path_local = __DIR__ . '/../' . $logo_rel;
            $base = function_exists('siteUrl') ? siteUrl() : (defined('BASE_URL') ? BASE_URL : '');

            if (is_string($base) && stripos($base, 'http') === 0
                && stripos($base, 'localhost') === false && strpos($base, '127.0.0.1') === false) {
                // URL pública absoluta (produção): a logo é carregada do servidor.
                $logo_url = rtrim($base, '/') . '/' . $logo_rel;
            } elseif (file_exists($logo_path_local)) {
                // Sem domínio público conhecido (ex.: teste local): embute como fallback.
                try {
                    $mail->addEmbeddedImage($logo_path_local, 'logo_cid');
                    $logo_url = 'cid:logo_cid';
                } catch (Exception $e) {
                    log_activity("FALHA (PHPMailer): não foi possível embutir a logo. " . $e->getMessage());
                    $logo_url = '';
                }
            }
        }

        // Monta a mensagem baseada no template
        ob_start();
        // Usa __DIR__ . '/../' para voltar do diretório 'lib' para a raiz
        include __DIR__ . '/../email_templates/' . $template . '.php';
        $mensagem = ob_get_clean();

        //Conteúdo do E-mail
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body    = $mensagem;
        $mail->AltBody = 'Para visualizar esta mensagem, por favor, use um cliente de e-mail compatível com HTML.';

        $mail->send();
        log_activity("Email (PHPMailer) enviado para $para_email, assunto: $assunto");
        return true;
    } catch (Exception $e) {
        log_activity("FALHA (PHPMailer) ao enviar email para $para_email: {$mail->ErrorInfo}");
        return false;
    }
}
?>
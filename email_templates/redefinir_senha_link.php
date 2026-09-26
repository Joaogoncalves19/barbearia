<?php
// Template: redefinição de senha. Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$link = $d['link_redefinicao'] ?? '#';

$conteudo = '<p style="text-align:center; margin:0;">Você solicitou a redefinição da sua senha de acesso. Clique no botão abaixo para criar uma nova senha e recuperar o acesso à sua conta.</p>';
$conteudo .= '<p style="text-align:center; margin:18px 0 0; color:#64748b; font-size:14px;">Se você não solicitou essa alteração, ignore este e-mail — sua senha atual permanece segura.</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Segurança', 'cor' => '#475569'],
    'preheader' => 'Redefina sua senha de acesso.',
    'titulo' => 'Recuperação de senha 🔒',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>.',
    'conteudo' => $conteudo,
    'cta' => ['url' => $link, 'texto' => 'Criar nova senha'],
    'cta_fallback' => $link,
    'mostrar_contato' => false,
]);

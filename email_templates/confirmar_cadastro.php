<?php
// Template: confirmação de cadastro (verificar e-mail). Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$nome_barbearia = htmlspecialchars(($configGeral['nome_barbearia'] ?? (function_exists('carregarConfigGeral') ? (carregarConfigGeral()['nome_barbearia'] ?? 'Barbearia') : 'Barbearia')));
$link = $d['link_confirmacao'] ?? '#';

$conteudo = '<p style="text-align:center; margin:0;">Bem-vindo(a) à <strong>' . $nome_barbearia . '</strong>! Para garantir a segurança da sua conta e começar a agendar, confirme o seu endereço de e-mail no botão abaixo.</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Cadastro', 'cor' => '#3b82f6'],
    'preheader' => 'Falta um passo para ativar sua conta.',
    'titulo' => 'Falta apenas um passo! 🚀',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
    'cta' => ['url' => $link, 'texto' => 'Confirmar meu e-mail'],
    'cta_fallback' => $link,
    'mostrar_contato' => false,
]);

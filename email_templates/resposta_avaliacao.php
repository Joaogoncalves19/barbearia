<?php
// Template: resposta à avaliação do cliente. Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$nome_barbearia = htmlspecialchars(($configGeral['nome_barbearia'] ?? (function_exists('carregarConfigGeral') ? (carregarConfigGeral()['nome_barbearia'] ?? 'Barbearia') : 'Barbearia')));

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Obrigado pelo seu feedback na <strong>' . $nome_barbearia . '</strong>. Veja o que respondemos:</p>';
$conteudo .= emailInfoBox(nl2br(htmlspecialchars($d['resposta'] ?? '')));
$conteudo .= '<p style="text-align:center; margin:0; color:#64748b; font-size:14px;">Esperamos ver você novamente em breve!</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Resposta', 'cor' => '#0ea5e9'],
    'preheader' => 'Respondemos à sua avaliação.',
    'titulo' => 'Respondemos à sua avaliação 💬',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
]);

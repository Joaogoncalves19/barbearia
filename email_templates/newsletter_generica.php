<?php
// Template: newsletter / campanha genérica (corpo HTML livre). Layout unificado.
$d = $dados;
$corpo = $d['corpo_email'] ?? 'Olá!';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'preheader' => $d['assunto_email'] ?? 'Novidades da barbearia',
    'titulo' => htmlspecialchars($d['assunto_email'] ?? ''),
    'conteudo' => '<div style="font-size:15px; line-height:1.7; color:#334155;">' . $corpo . '</div>',
    'unsub' => $d['link_descadastro'] ?? '',
]);

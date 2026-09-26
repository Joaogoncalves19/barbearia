<?php
// Template: agendamento cancelado pelo estabelecimento. Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Informamos que o seu atendimento para o dia <strong>'
    . htmlspecialchars($d['data_agendamento'] ?? '') . '</strong> às <strong>' . htmlspecialchars($d['hora_agendamento'] ?? '')
    . '</strong> não poderá ser realizado e foi cancelado pelo estabelecimento.</p>';
$conteudo .= emailInfoBox('<div style="text-align:center;">Pedimos desculpas pelo transtorno. 🙏<br>Você pode escolher um novo horário quando quiser pelo nosso site.</div>');
$conteudo .= '<p style="text-align:center; margin:0; color:#64748b; font-size:14px;">Em caso de dúvidas, fale com a gente.</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Cancelado', 'cor' => '#ef4444'],
    'preheader' => 'Seu agendamento foi cancelado.',
    'titulo' => 'Seu agendamento foi cancelado',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>.',
    'conteudo' => $conteudo,
    'cta' => ['url' => (function_exists('siteUrl') ? rtrim(siteUrl(), '/') . '/agendamento' : 'agendamento'), 'texto' => 'Reagendar um horário'],
]);

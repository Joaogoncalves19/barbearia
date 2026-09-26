<?php
// Template: lembrete de avaliação. Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$base = function_exists('siteUrl') ? siteUrl() : (defined('BASE_URL') ? BASE_URL : '');
$lk = $d['link_avaliacao'] ?? (rtrim($base, '/') . '/cliente');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Você nos visitou recentemente em <strong>' . htmlspecialchars($d['data_agendamento'] ?? '') . '</strong>, atendido por <strong>' . htmlspecialchars($d['barbeiro'] ?? 'nosso time') . '</strong>.</p>';
$conteudo .= emailInfoBox('<div style="text-align:center;">A sua opinião é fundamental para continuarmos melhorando e entregando a melhor experiência. É rápido: deixe sua nota e um comentário sobre o corte e o atendimento.</div>');

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Avaliação', 'cor' => '#f59e0b'],
    'preheader' => 'Conte pra gente como foi seu atendimento.',
    'titulo' => 'Como foi o seu atendimento? ⭐',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
    'cta' => ['url' => $lk, 'texto' => '⭐ Avaliar atendimento'],
    'cta_fallback' => $lk,
]);

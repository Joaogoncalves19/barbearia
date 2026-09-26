<?php
// Template: pedido de avaliação (com estrelas clicáveis). Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$nome_barbearia = htmlspecialchars(($configGeral['nome_barbearia'] ?? (function_exists('carregarConfigGeral') ? (carregarConfigGeral()['nome_barbearia'] ?? 'Barbearia') : 'Barbearia')));

if (!empty($d['link_avaliacao'])) {
    $link_avaliacao = $d['link_avaliacao'];
} else {
    $base = function_exists('siteUrl') ? siteUrl() : (defined('BASE_URL') ? BASE_URL : (($_SERVER['HTTP_HOST'] ?? 'localhost') . '/'));
    $link_avaliacao = rtrim($base, '/') . '/login_cliente.php';
}
$sep = (strpos($link_avaliacao, '?') !== false) ? '&' : '?';

$estrelas = '';
for ($s = 1; $s <= 5; $s++) {
    $estrelas .= '<a href="' . htmlspecialchars($link_avaliacao . $sep . 'r=' . $s) . '" style="text-decoration:none; color:#fbbf24; font-size:40px; margin:0 2px;">★</a>';
}

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Esperamos que você tenha saído satisfeito do seu atendimento na <strong>' . $nome_barbearia . '</strong> em <strong>' . htmlspecialchars($d['data_agendamento'] ?? '') . ' às ' . htmlspecialchars($d['hora_agendamento'] ?? '') . '</strong>.</p>';
$conteudo .= '<p style="text-align:center; margin:14px 0 6px;">Toque em uma estrela para avaliar:</p>';
$conteudo .= '<div style="text-align:center; margin:6px 0 8px;">' . $estrelas . '</div>';
$conteudo .= '<p style="text-align:center; margin:0;">Sua opinião nos ajuda a melhorar todos os dias. Leva menos de 1 minuto! 🙏</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Avaliação', 'cor' => '#f59e0b'],
    'preheader' => 'Como foi o seu atendimento?',
    'titulo' => 'Como foi sua experiência? ⭐',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>.',
    'conteudo' => $conteudo,
    'cta' => ['url' => $link_avaliacao, 'texto' => 'Deixar minha avaliação'],
]);

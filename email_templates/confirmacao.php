<?php
// Template: confirmação de agendamento. Usa o layout unificado (emailLayout).
$d = $dados;
$tipo_desconto = $d['tipo_desconto'] ?? '';
$is_assinatura = in_array($tipo_desconto, ['adesao_plano', 'assinatura_vip', 'assinatura', 'plano']);
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');

$detalhes = '<strong>📅 Data:</strong> ' . htmlspecialchars($d['data_agendamento'] ?? '') . '<br>'
    . '<strong>⏰ Horário:</strong> ' . htmlspecialchars($d['hora_agendamento'] ?? '') . '<br>'
    . '<strong>✂️ Serviços:</strong> ' . htmlspecialchars(implode(', ', $d['servicos'] ?? [])) . '<br>'
    . '<strong>💈 Profissional:</strong> ' . htmlspecialchars($d['barbeiro'] ?? '');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Sua vaga está garantida na nossa agenda! Confira os detalhes:</p>';
$conteudo .= emailInfoBox($detalhes);

if ($is_assinatura) {
    $conteudo .= '<p style="text-align:center; margin:0; color:#635bff; font-weight:700;">👑 Cliente Barbearia por Assinatura — serviços 100% cobertos pelo seu plano!</p>';
} elseif (!empty($tipo_desconto)) {
    $conteudo .= '<p style="text-align:center; margin:0; color:#059669; font-weight:700;">🏷️ Benefício aplicado: ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $tipo_desconto))) . '</p>';
}
$conteudo .= '<p style="text-align:center; margin:18px 0 0;">Precisa reagendar ou cancelar? Faça isso na aba <strong>“Minha Conta”</strong> com antecedência. Esperamos você! 😊</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Confirmado', 'cor' => '#10b981'],
    'preheader' => 'Seu agendamento está confirmado.',
    'titulo' => 'Agendamento confirmado! ✅',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
    'cta' => ['url' => (function_exists('siteUrl') ? rtrim(siteUrl(), '/') . '/cliente' : 'cliente'), 'texto' => 'Ver na Minha Conta'],
]);

<?php
// Template: lembrete de agendamento (véspera e "horas antes"). Layout unificado.
$d = $dados;
$tipo = $d['tipo_lembrete'] ?? 'vespera';        // 'vespera' | 'hora'
$quando = $d['quando_txt'] ?? 'amanhã';
$falta = $d['falta_txt'] ?? '';
$tipo_desconto = $d['tipo_desconto'] ?? '';
$is_assinatura = in_array($tipo_desconto, ['adesao_plano', 'assinatura_vip', 'assinatura', 'plano']);
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$horaFmt = htmlspecialchars($d['hora_agendamento'] ?? '');

$titulo = ($tipo === 'hora') ? 'Seu horário é logo mais! ⏰' : 'Falta pouco para o seu horário! ⏳';
$intro = ($tipo === 'hora')
    ? ('Passando para lembrar: seu horário é <strong>hoje às ' . $horaFmt . '</strong>' . ($falta ? ' (' . htmlspecialchars($falta) . ')' : '') . '.')
    : ('Este é um lembrete de que você tem um horário marcado conosco para <strong>' . htmlspecialchars($quando) . '</strong>.');

$detalhes = '<strong>📅 Data:</strong> ' . htmlspecialchars($d['data_agendamento'] ?? '') . '<br>'
    . '<strong>⏰ Horário:</strong> ' . $horaFmt . '<br>'
    . '<strong>✂️ Serviços:</strong> ' . htmlspecialchars(implode(', ', $d['servicos'] ?? [])) . '<br>'
    . '<strong>💈 Profissional:</strong> ' . htmlspecialchars($d['barbeiro'] ?? '');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">' . $intro . '</p>';
$conteudo .= emailInfoBox($detalhes);
if ($is_assinatura) {
    $conteudo .= '<p style="text-align:center; margin:0 0 8px; color:#635bff; font-weight:700;">👑 Serviços deste horário cobertos pelo seu plano de assinatura!</p>';
}
if (!empty($d['link_confirmar'])) {
    $conteudo .= '<p style="text-align:center; margin:16px 0 0;">Você vem? Confirme sua presença em um clique — assim garantimos tudo pronto para você. 🙌</p>';
}
$conteudo .= '<p style="text-align:center; margin:18px 0 0; color:#64748b; font-size:14px;">Chegue com ~10 min de antecedência. Precisa remarcar? Acesse <strong>“Minha Conta”</strong>.</p>';

$opts = [
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Lembrete', 'cor' => '#f59e0b'],
    'preheader' => ($tipo === 'hora') ? ('Seu horário é hoje às ' . $horaFmt) : 'Você tem horário marcado ' . $quando . '.',
    'titulo' => $titulo,
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
];
if (!empty($d['link_confirmar'])) {
    $opts['cta'] = ['url' => $d['link_confirmar'], 'texto' => '✅ Confirmar presença'];
}
echo emailLayout($opts);

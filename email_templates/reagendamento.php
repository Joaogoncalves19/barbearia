<?php
// Template: agendamento reagendado. Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$servicos_lista = (isset($d['servicos']) && is_array($d['servicos'])) ? implode(', ', $d['servicos']) : ($d['servicos'] ?? 'Serviços na comanda');

$detalhes = '<strong>📅 Nova data:</strong> ' . htmlspecialchars($d['data_agendamento'] ?? '') . '<br>'
    . '<strong>⏰ Novo horário:</strong> ' . htmlspecialchars($d['hora_agendamento'] ?? '') . '<br>'
    . '<strong>✂️ Serviços:</strong> ' . htmlspecialchars($servicos_lista) . '<br>'
    . '<strong>💈 Profissional:</strong> ' . htmlspecialchars($d['barbeiro'] ?? 'Não especificado');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">O seu agendamento foi reagendado com sucesso. Confira os novos detalhes:</p>';
$conteudo .= emailInfoBox($detalhes);
$conteudo .= '<p style="text-align:center; margin:0; color:#64748b; font-size:14px;">Precisa alterar de novo ou cancelar? Acesse <strong>“Minha Conta”</strong>. Estamos te esperando!</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Reagendado', 'cor' => '#0ea5e9'],
    'preheader' => 'Seu horário foi atualizado.',
    'titulo' => 'Seu horário foi atualizado! 🔄',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>.',
    'conteudo' => $conteudo,
    'cta' => ['url' => (function_exists('siteUrl') ? rtrim(siteUrl(), '/') . '/cliente' : 'cliente'), 'texto' => 'Ver na Minha Conta'],
]);

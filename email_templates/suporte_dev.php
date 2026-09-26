<?php
// Template: mensagem de suporte enviada aos desenvolvedores. Layout unificado.
$d = $dados;

$meta = '<strong>Remetente (logado):</strong> ' . htmlspecialchars($d['remetente_usuario'] ?? '') . '<br>'
    . '<strong>Contato para retorno:</strong> ' . htmlspecialchars($d['contato_retorno'] ?? 'Não informado') . '<br>'
    . '<strong>Tipo de chamado:</strong> ' . htmlspecialchars($d['tipo'] ?? '') . '<br>'
    . '<strong>Data/Hora:</strong> ' . date('d/m/Y H:i:s') . '<br>'
    . '<strong>Servidor de origem:</strong> ' . htmlspecialchars($d['servidor'] ?? '');

$conteudo = emailInfoBox($meta);
$conteudo .= '<p style="margin:18px 0 8px; font-weight:700; color:#0f172a;">Mensagem:</p>';
$conteudo .= '<div style="background:#fffbeb; border-left:4px solid #f59e0b; padding:14px 16px; border-radius:0 10px 10px 0; color:#475569; font-size:15px; line-height:1.6;">' . ($d['mensagem'] ?? '') . '</div>';
if (!empty($d['contexto'])) {
    $conteudo .= '<p style="margin:18px 0 8px; font-weight:700; color:#0f172a;">Informações técnicas:</p>';
    $conteudo .= '<div style="background:#f1f5f9; border-left:4px solid #64748b; padding:14px 16px; border-radius:0 10px 10px 0; color:#475569; font-size:12px; line-height:1.6; font-family:monospace; word-break:break-word;">' . $d['contexto'] . '</div>';
}

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Suporte', 'cor' => '#ef4444'],
    'preheader' => 'Nova mensagem do sistema.',
    'titulo' => 'Nova mensagem: ' . htmlspecialchars($d['nomeBarbearia'] ?? ''),
    'conteudo' => $conteudo,
    'mostrar_contato' => false,
]);

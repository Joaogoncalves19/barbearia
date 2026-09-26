<?php
// Template: reativação de cliente (cupom de retorno). Layout unificado.
$d = $dados;
$nomeCli = htmlspecialchars($d['nome_cliente'] ?? 'Cliente');
$cupomPct = htmlspecialchars($d['cupom_desconto'] ?? 'um');
$cupomCod = htmlspecialchars($d['cupom_codigo'] ?? 'VOLTA10');

$conteudo = '<p style="text-align:center; margin:0 0 4px;">Faz um tempo desde o seu último corte com a gente. Já está na hora de dar aquele trato no visual, não é? ✂️</p>';
$conteudo .= '<p style="text-align:center; margin:0;">Para comemorar seu retorno, preparamos um presente: <strong>' . $cupomPct . '% de desconto</strong> no seu próximo agendamento!</p>';
$conteudo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td align="center">'
    . '<div style="display:inline-block; background:#fff7ed; border:2px dashed #f59e0b; border-radius:12px; padding:18px 34px;">'
    . '<div style="color:#92400e; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Seu código de desconto</div>'
    . '<div style="font-size:30px; font-weight:900; color:#d97706; letter-spacing:3px;">' . $cupomCod . '</div>'
    . '</div></td></tr></table>';
$conteudo .= '<p style="text-align:center; margin:0; color:#64748b; font-size:14px;">Agende seu horário e informe o cupom na finalização. Aguardamos a sua visita!</p>';

echo emailLayout([
    'logo' => $logo_url ?? '',
    'chip' => ['texto' => 'Presente', 'cor' => '#f97316'],
    'preheader' => 'Um presente para o seu retorno.',
    'titulo' => 'Sentimos a sua falta! 🎁',
    'saudacao' => 'Olá, <strong>' . $nomeCli . '</strong>!',
    'conteudo' => $conteudo,
    'cta' => ['url' => (function_exists('siteUrl') ? rtrim(siteUrl(), '/') . '/agendamento' : 'agendamento'), 'texto' => 'Agendar e usar desconto'],
]);

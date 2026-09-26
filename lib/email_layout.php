<?php
// lib/email_layout.php
// Layout unificado dos e-mails: todos os templates de email_templates/ chamam
// emailLayout() para ficarem visualmente idênticos (mesma marca, cabeçalho,
// botões e rodapé). Feito com tabelas + estilos inline (compatível com clientes
// de e-mail). A cor de destaque vem do tema do painel, como no chatbot.

/** Cor de destaque da marca (tema do painel). */
function emailAccent() {
    $theme = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
    $ac = $theme['secondary_color'] ?? ($theme['primary_color'] ?? '#6366f1');
    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$ac)) $ac = '#6366f1';
    return $ac;
}

/** Resolve a URL do logo (usa o CID embutido quando disponível). */
function emailLogoUrl($logo_url = '') {
    if (!empty($logo_url)) return $logo_url; // CID embutido ou URL já resolvida
    $cfg = function_exists('carregarConfigGeral') ? carregarConfigGeral() : [];
    $logo_path = $cfg['logo_path'] ?? 'uploads/logo.png';
    $base = function_exists('siteUrl') ? siteUrl() : (defined('BASE_URL') ? BASE_URL : '');
    // Sem domínio público conhecido → não gera URL quebrada (mostra o nome no lugar).
    if ($base === '' || stripos($base, 'http') !== 0 || stripos($base, 'localhost') !== false || strpos($base, '127.0.0.1') !== false) {
        return '';
    }
    return rtrim($base, '/') . '/' . ltrim($logo_path, '/');
}

/** Botão CTA padronizado (HTML). */
function emailBotao($url, $texto, $cor = null) {
    $cor = $cor ?: emailAccent();
    return '<a href="' . htmlspecialchars($url) . '" style="display:inline-block; background:' . htmlspecialchars($cor)
        . '; color:#ffffff !important; text-decoration:none; padding:14px 32px; border-radius:10px; font-weight:700; font-size:16px;">'
        . $texto . '</a>';
}

/** Caixa de destaque (detalhes do agendamento, etc.). */
function emailInfoBox($conteudoHtml, $cor = null) {
    $cor = $cor ?: emailAccent();
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e8edf3; border-left:4px solid '
        . htmlspecialchars($cor) . '; border-radius:10px; margin:22px 0;"><tr><td style="padding:18px 22px; color:#334155; font-size:15px; line-height:1.7;">'
        . $conteudoHtml . '</td></tr></table>';
}

/**
 * Monta o e-mail completo. Opções:
 *  accent, logo, preheader, chip(['texto','cor']), titulo, saudacao, conteudo(HTML),
 *  cta(['url','texto','cor']), cta_fallback(url), unsub(url), mostrar_contato(bool),
 *  assinatura_texto(bool) — encerramento padrão.
 */
function emailLayout(array $o) {
    $accent = $o['accent'] ?? emailAccent();
    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$accent)) $accent = emailAccent();

    $cfg   = function_exists('carregarConfigGeral') ? carregarConfigGeral() : [];
    $nome  = htmlspecialchars($cfg['nome_barbearia'] ?? 'Barbearia');
    $logo  = emailLogoUrl($o['logo'] ?? '');
    $pre   = trim((string)($o['preheader'] ?? ''));
    $titulo   = $o['titulo'] ?? '';
    $saud     = $o['saudacao'] ?? '';
    $conteudo = $o['conteudo'] ?? '';
    $chip  = $o['chip'] ?? null;
    $cta   = $o['cta'] ?? null;
    $ctaFb = trim((string)($o['cta_fallback'] ?? ''));
    $unsub = trim((string)($o['unsub'] ?? ''));
    $mostrarContato = $o['mostrar_contato'] ?? true;

    $tel   = trim((string)($cfg['telefone_contato'] ?? ''));
    $end   = trim((string)($cfg['endereco'] ?? ''));
    $insta = trim((string)($cfg['link_instagram'] ?? ''));
    $face  = trim((string)($cfg['link_facebook'] ?? ''));
    $wpp   = trim((string)($cfg['link_whatsapp'] ?? ''));

    ob_start(); ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title><?= $titulo !== '' ? strip_tags($titulo) : $nome ?></title>
</head>
<body style="margin:0; padding:0; background:#eef2f7;">
<?php if ($pre !== ''): ?>
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#eef2f7; font-size:1px; line-height:1px;"><?= htmlspecialchars($pre) ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;">
<tr><td align="center" style="padding:26px 14px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 8px 30px rgba(15,23,42,.08); border-top:5px solid <?= htmlspecialchars($accent) ?>; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

    <!-- Cabeçalho -->
    <tr><td align="center" style="padding:28px 30px 18px; border-bottom:1px solid #f1f5f9;">
      <?php if ($logo !== ''): ?>
        <img src="<?= htmlspecialchars($logo) ?>" alt="<?= $nome ?>" height="100" style="height:100px; max-height:100px; max-width:300px; width:auto; object-fit:contain; display:block; margin:0 auto; border:0;">
      <?php else: ?>
        <div style="font-size:22px; font-weight:800; color:#1e293b; letter-spacing:-.3px;"><?= $nome ?></div>
      <?php endif; ?>
    </td></tr>

    <!-- Corpo -->
    <tr><td style="padding:30px 34px 12px;">
      <?php if ($chip): $chipCor = $chip['cor'] ?? $accent; ?>
      <div style="text-align:center; margin-bottom:16px;">
        <span style="display:inline-block; background:#ffffff; color:<?= htmlspecialchars($chipCor) ?>; border:1.5px solid <?= htmlspecialchars($chipCor) ?>; border-radius:999px; padding:4px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.5px;"><?= $chip['texto'] ?? '' ?></span>
      </div>
      <?php endif; ?>

      <?php if ($titulo !== ''): ?>
      <h1 style="text-align:center; color:#0f172a; font-size:22px; line-height:1.3; margin:0 0 <?= $saud !== '' ? '6px' : '18px' ?>;"><?= $titulo ?></h1>
      <?php endif; ?>

      <?php if ($saud !== ''): ?>
      <p style="text-align:center; color:#334155; font-size:16px; margin:0 0 18px;"><?= $saud ?></p>
      <?php endif; ?>

      <div style="color:#475569; font-size:15px; line-height:1.7;">
        <?= $conteudo ?>
      </div>

      <?php if ($cta && !empty($cta['url'])): ?>
      <div style="text-align:center; margin:30px 0 6px;">
        <?= emailBotao($cta['url'], $cta['texto'] ?? 'Abrir', $cta['cor'] ?? $accent) ?>
      </div>
      <?php endif; ?>

      <?php if ($ctaFb !== ''): ?>
      <p style="text-align:center; color:#94a3b8; font-size:12px; margin:14px 0 0;">
        Se o botão não funcionar, copie e cole no navegador:<br>
        <a href="<?= htmlspecialchars($ctaFb) ?>" style="color:<?= htmlspecialchars($accent) ?>; word-break:break-all;"><?= htmlspecialchars($ctaFb) ?></a>
      </p>
      <?php endif; ?>
    </td></tr>

    <!-- Rodapé -->
    <tr><td style="padding:22px 30px 26px; background:#f8fafc; border-top:1px solid #eef2f7;">
      <p style="text-align:center; margin:0 0 6px; color:#334155; font-size:14px; font-weight:700;"><?= $nome ?></p>
      <?php if ($mostrarContato && ($end !== '' || $tel !== '')): ?>
      <p style="text-align:center; margin:0 0 8px; color:#64748b; font-size:12px; line-height:1.6;">
        <?= $end !== '' ? '📍 ' . htmlspecialchars($end) : '' ?><?= ($end !== '' && $tel !== '') ? ' &nbsp;·&nbsp; ' : '' ?><?= $tel !== '' ? '📞 ' . htmlspecialchars($tel) : '' ?>
      </p>
      <?php endif; ?>
      <?php if ($mostrarContato && ($insta || $face || $wpp)): ?>
      <p style="text-align:center; margin:0 0 8px; font-size:12px;">
        <?php if ($insta): ?><a href="<?= htmlspecialchars($insta) ?>" style="color:<?= htmlspecialchars($accent) ?>; text-decoration:none; margin:0 6px;">Instagram</a><?php endif; ?>
        <?php if ($face): ?><a href="<?= htmlspecialchars($face) ?>" style="color:<?= htmlspecialchars($accent) ?>; text-decoration:none; margin:0 6px;">Facebook</a><?php endif; ?>
        <?php if ($wpp): ?><a href="<?= htmlspecialchars($wpp) ?>" style="color:<?= htmlspecialchars($accent) ?>; text-decoration:none; margin:0 6px;">WhatsApp</a><?php endif; ?>
      </p>
      <?php endif; ?>
      <p style="text-align:center; margin:6px 0 0; color:#94a3b8; font-size:11px; line-height:1.6;">
        Este é um e-mail automático<?= $mostrarContato ? ', por favor não responda.' : '.' ?>
        <?php if ($unsub !== ''): ?><br><a href="<?= htmlspecialchars($unsub) ?>" style="color:#94a3b8; text-decoration:underline;">Cancelar inscrição</a><?php endif; ?>
      </p>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
<?php
    return ob_get_clean();
}

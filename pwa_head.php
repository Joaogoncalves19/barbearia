<?php
/**
 * Tags do PWA para injetar dentro do <head> das páginas voltadas ao cliente.
 * Uso:  <?php include __DIR__ . '/pwa_head.php'; ?>
 */
if (!isset($__pwaTheme)) {
    $__pwaTheme = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
}
$__pwaColor = $__pwaTheme['primary_color'] ?? '#111827';
if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $__pwaColor)) {
    $__pwaColor = '#111827';
}
// Versão do ícone (mtime) para forçar atualização do cache quando a logo muda.
$__pwaIconFile = __DIR__ . '/uploads/pwa-icon-192.png';
$__pwaIconVer  = is_file($__pwaIconFile) ? (int)@filemtime($__pwaIconFile) : 0;
$__pwaIconUrl  = 'uploads/pwa-icon-192.png?v=' . $__pwaIconVer;
?>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="<?= htmlspecialchars($__pwaColor, ENT_QUOTES) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($__pwaIconUrl, ENT_QUOTES) ?>">
<script>
  window.__pwaThemeColor = <?= json_encode($__pwaColor) ?>;
  window.__pwaIcon = <?= json_encode($__pwaIconUrl) ?>;
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('js/sw.js', { scope: './' }).catch(function () {});
    });
  }
</script>
<script src="<?= assetUrl('js/pwa_install.js') ?>" defer></script>

<?php
// Define o tipo de conteúdo como CSS
header('Content-Type: text/css; charset=UTF-8');

// Tema dinâmico (lê o SQLite): NUNCA deve ficar preso em cache, senão a cor
// nova definida no painel não aparece. Forçamos revalidação sempre; o ETag
// (calculado mais abaixo) permite resposta 304 quando o tema não mudou.
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Define as cores padrão caso não haja configuração salva
$default_colors = [
    'primary-color' => '#333',
    'secondary-color' => '#007bff',
    'background-color' => '#f8f9fa',
    'card-background' => '#fff',
    'border-color' => '#e0e0e0',
    'success-color' => '#28a745',
    'error-color' => '#dc3545',
    'text-color' => '#495057',
    'warning-color' => '#ffc107'
];

$colors = $default_colors;

/**
 * Aplica um array de cores salvas sobre $colors, aceitando tanto as chaves
 * com hífen (secondary-color) quanto as com underscore (secondary_color)
 * usadas pelo painel (SQLite).
 */
function _themeAplicar(array $saved, array &$colors) {
    foreach ($saved as $k => $v) {
        if (!is_string($v) || trim($v) === '') continue;
        $chave = str_replace('_', '-', $k); // secondary_color -> secondary-color
        if (array_key_exists($chave, $colors)) {
            $colors[$chave] = $v;
        }
    }
}

// 1) Fonte de verdade: tema salvo no banco (SQLite) pelo painel (seção theme_config).
$dbPath = __DIR__ . '/../_dados/database.sqlite';
if (file_exists($dbPath)) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $stmt = $pdo->query("SELECT dados_json FROM configuracoes WHERE secao = 'theme_config'");
        $json = $stmt ? $stmt->fetchColumn() : false;
        if ($json) {
            $saved = json_decode($json, true);
            if (is_array($saved)) _themeAplicar($saved, $colors);
        }
    } catch (Exception $e) { /* mantém defaults/legado */ }
}

// 2) Compatibilidade com instalações antigas que usavam o arquivo JSON.
$themeConfigFile = __DIR__ . '/../_dados/theme_config.json';
if (file_exists($themeConfigFile)) {
    $saved_colors = json_decode((string)file_get_contents($themeConfigFile), true);
    if (is_array($saved_colors)) _themeAplicar($saved_colors, $colors);
}

// Sanitização: só aceita cores CSS seguras (hex, rgb/rgba, hsl/hsla ou nomes simples).
function _themeCorSegura($v, $fallback) {
    $v = trim((string)$v);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return $v;
    if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/i', $v)) return $v;
    if (preg_match('/^[a-zA-Z]{3,20}$/', $v)) return $v; // nomes de cores CSS
    return $fallback;
}
foreach ($colors as $k => $v) {
    $colors[$k] = _themeCorSegura($v, $default_colors[$k]);
}

// Monta o CSS final e calcula um ETag a partir do próprio conteúdo. Assim o
// navegador pode revalidar barato: se o tema não mudou, devolvemos 304; se a
// cor foi alterada no painel, o ETag muda e o CSS novo é entregue na hora.
$css  = "/* Estilos Personalizados do Tema (cores definidas no painel) */\n";
$css .= ":root {\n";
$css .= "    --primary-color: {$colors['primary-color']};\n";
$css .= "    --secondary-color: {$colors['secondary-color']};\n";
$css .= "    --background-color: {$colors['background-color']};\n";
$css .= "    --card-background: {$colors['card-background']};\n";
$css .= "    --border-color: {$colors['border-color']};\n";
$css .= "    --success-color: {$colors['success-color']};\n";
$css .= "    --error-color: {$colors['error-color']};\n";
$css .= "    --text-color: {$colors['text-color']};\n";
$css .= "    --warning-color: {$colors['warning-color']};\n";
$css .= "}\n";

$etag = '"' . md5($css) . '"';
header('ETag: ' . $etag);

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

echo $css;

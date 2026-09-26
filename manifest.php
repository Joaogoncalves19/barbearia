<?php
/**
 * Web App Manifest dinâmico do PWA.
 * Segue o nome da barbearia e a cor do tema definidos no painel.
 */
require_once __DIR__ . '/functions.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$cfg   = function_exists('carregarConfigGeral') ? carregarConfigGeral() : [];
$theme = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];

$nome    = trim($cfg['nome_barbearia'] ?? 'Barbearia');
$primary = $theme['primary_color'] ?? '#111827';
if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $primary)) {
    $primary = '#111827';
}

$manifest = [
    'name'             => $nome,
    'short_name'       => mb_substr($nome, 0, 18, 'UTF-8'),
    'description'      => 'Agende seu horário e gerencie seus atendimentos.',
    'start_url'        => 'cliente.php',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#ffffff',
    'theme_color'      => $primary,
    'lang'             => 'pt-BR',
    'icons'            => pwaManifestIcons($cfg),
];

/**
 * Monta a lista de ícones do manifest.
 * Prefere os ícones gerados (nítidos, 192/512 + maskable); se não existirem
 * (ex.: servidor sem GD), cai para o logo configurado, sempre com o tipo real.
 */
function pwaManifestIcons(array $cfg): array
{
    $root = __DIR__;
    if (is_file($root . '/uploads/pwa-icon-512.png')) {
        // ?v=mtime evita que navegadores/iOS sirvam o ícone antigo em cache.
        $v = (string)(@filemtime($root . '/uploads/pwa-icon-512.png') ?: 0);
        return [
            ['src' => 'uploads/pwa-icon-192.png?v=' . $v,     'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'uploads/pwa-icon-512.png?v=' . $v,     'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'uploads/pwa-maskable-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    // Fallback: usa o logo atual diretamente.
    $logo = trim($cfg['logo_path'] ?? '');
    $cands = [];
    if ($logo !== '') {
        $cands[] = $logo;
        $cands[] = $root . '/' . ltrim(str_replace('\\', '/', $logo), '/');
    }
    $cands[] = $root . '/uploads/logo.png';

    foreach ($cands as $c) {
        if ($c && is_file($c)) {
            $info = @getimagesize($c);
            if ($info) {
                $src = ($logo !== '' && (is_file($root . '/' . ltrim($logo, '/')) || is_file($logo)))
                    ? ltrim(str_replace('\\', '/', $logo), '/')
                    : 'uploads/logo.png';
                return [[
                    'src'     => $src . '?v=' . (@filemtime($c) ?: 0),
                    'sizes'   => $info[0] . 'x' . $info[1],
                    'type'    => $info['mime'] ?? 'image/png',
                    'purpose' => 'any maskable',
                ]];
            }
        }
    }
    // Último recurso: aponta para o logo padrão mesmo sem conseguir medir.
    return [['src' => 'uploads/logo.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']];
}

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

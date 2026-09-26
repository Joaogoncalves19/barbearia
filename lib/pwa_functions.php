<?php
/**
 * Geração automática dos ícones do PWA a partir do logo da barbearia.
 *
 * Estratégia sem dependência obrigatória de GD:
 *  - Se a extensão GD estiver disponível: gera pwa-icon-192/512 (contain, fundo
 *    transparente) e pwa-maskable-512 (logo sobre a cor primária, com safe-zone).
 *  - Se GD NÃO estiver disponível: remove ícones gerados antigos, para que o
 *    manifest.php caia automaticamente no logo atual (sempre com o tipo correto).
 *
 * Deve ser chamada sempre que o logo mudar (ver actions/configuracoes.php).
 */

if (!function_exists('pwaRootDir')) {
    function pwaRootDir(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('pwaResolverLogo')) {
    /**
     * Resolve o caminho absoluto do logo de origem para os ícones.
     * Aceita o logo_path salvo no config (relativo) e cai para uploads/logo.png.
     */
    function pwaResolverLogo(?string $logoPath = null): ?string
    {
        $root = pwaRootDir();
        $candidatos = [];
        if ($logoPath) {
            $candidatos[] = $logoPath; // caminho absoluto, se vier
            $candidatos[] = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $logoPath), '/');
        }
        $candidatos[] = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo.png';

        foreach ($candidatos as $c) {
            if ($c && is_file($c)) {
                $ext = strtolower(pathinfo($c, PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                    return $c;
                }
            }
        }
        return null;
    }
}

if (!function_exists('pwaCriarImagemFonte')) {
    function pwaCriarImagemFonte(string $path)
    {
        $info = @getimagesize($path);
        if (!$info) return null;
        switch ($info['mime']) {
            case 'image/png':  return @imagecreatefrompng($path);
            case 'image/jpeg': return @imagecreatefromjpeg($path);
            case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        }
        return null;
    }
}

if (!function_exists('regenerarIconesPWA')) {
    /**
     * @param string|null $logoPath  logo_path do config (opcional)
     * @param string|null $corPrimaria  cor de fundo do ícone maskable (#rrggbb)
     * @return array{ok:bool, gd:bool, msg:string}
     */
    function regenerarIconesPWA(?string $logoPath = null, ?string $corPrimaria = null): array
    {
        $root   = pwaRootDir();
        $iconDir = $root . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($iconDir)) {
            @mkdir($iconDir, 0755, true);
        }
        $alvos = [
            'any192'   => $iconDir . DIRECTORY_SEPARATOR . 'pwa-icon-192.png',
            'any512'   => $iconDir . DIRECTORY_SEPARATOR . 'pwa-icon-512.png',
            'maskable' => $iconDir . DIRECTORY_SEPARATOR . 'pwa-maskable-512.png',
        ];

        $src = pwaResolverLogo($logoPath);
        if ($src === null) {
            return ['ok' => false, 'gd' => false, 'msg' => 'Logo de origem não encontrado.'];
        }

        // Sem GD: apaga ícones fixos para o manifest usar o logo atual dinamicamente.
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefrompng')) {
            foreach ($alvos as $f) {
                if (is_file($f)) @unlink($f);
            }
            return ['ok' => true, 'gd' => false, 'msg' => 'GD indisponível: manifest usará o logo diretamente.'];
        }

        $srcImg = pwaCriarImagemFonte($src);
        if (!$srcImg) {
            return ['ok' => false, 'gd' => true, 'msg' => 'Não foi possível ler o logo (' . basename($src) . ').'];
        }
        $sw = imagesx($srcImg);
        $sh = imagesy($srcImg);

        // cor primária -> rgb (default escuro)
        $hex = ltrim((string)($corPrimaria ?? '#111827'), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '111827';
        }
        $pr = hexdec(substr($hex, 0, 2));
        $pg = hexdec(substr($hex, 2, 2));
        $pb = hexdec(substr($hex, 4, 2));

        $contain = function (int $size) use ($srcImg, $sw, $sh): \GdImage {
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            $scale = min($size / $sw, $size / $sh);
            $nw = max(1, (int)round($sw * $scale));
            $nh = max(1, (int)round($sh * $scale));
            imagecopyresampled($dst, $srcImg, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
            return $dst;
        };

        $ok = true;
        foreach (['any192' => 192, 'any512' => 512] as $k => $size) {
            $img = $contain($size);
            $ok = imagepng($img, $alvos[$k]) && $ok;
            imagedestroy($img);
        }

        // maskable: logo a 72% sobre a cor primária (safe-zone)
        $size = 512;
        $mask = imagecreatetruecolor($size, $size);
        imagefill($mask, 0, 0, imagecolorallocate($mask, $pr, $pg, $pb));
        $inner = (int)($size * 0.72);
        $scale = min($inner / $sw, $inner / $sh);
        $nw = max(1, (int)round($sw * $scale));
        $nh = max(1, (int)round($sh * $scale));
        imagecopyresampled($mask, $srcImg, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
        $ok = imagepng($mask, $alvos['maskable']) && $ok;
        imagedestroy($mask);
        imagedestroy($srcImg);

        return $ok
            ? ['ok' => true, 'gd' => true, 'msg' => 'Ícones do PWA gerados.']
            : ['ok' => false, 'gd' => true, 'msg' => 'Falha ao gravar os ícones (verifique permissão de escrita).'];
    }
}

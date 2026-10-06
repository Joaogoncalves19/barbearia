<?php

namespace App\Modules\Shared\Media;

use GdImage;

/**
 * Decodifica e reprocessa imagens com o GD (imagens.md §2):
 *
 * - aceita so JPEG, PNG e WebP pelo conteudo (finfo + getimagesize);
 * - limite de pixels (protege a memoria do servidor);
 * - aplica a orientacao EXIF das fotos de celular e DESCARTA os metadados
 *   (GPS, aparelho, data) ao reencodar;
 * - redimensiona sem cortar (o enquadramento e do CSS) e preserva a
 *   transparencia (logo);
 * - sai sempre WebP (qualidade 80).
 */
final class ImageProcessor
{
    private const MIMES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    /** 6000 x 6000 = 36 MP (foto de celular tem ~12 MP). */
    private const MAX_PIXELS = 36_000_000;

    /**
     * Confere tipo real e tamanho, sem decodificar a imagem inteira.
     *
     * @return array{0: int, 1: int, 2: int} largura, altura, tipo (IMAGETYPE_*)
     *
     * @throws InvalidImage
     */
    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new InvalidImage('Arquivo de imagem vazio ou inexistente.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! isset(self::MIMES[$mime])) {
            throw new InvalidImage('Envie uma imagem JPG, PNG ou WebP.');
        }
        $info = @getimagesize($path);
        if ($info === false || $info[2] !== self::MIMES[$mime] || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImage('O arquivo não é uma imagem válida.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidImage('Imagem grande demais (máximo de 36 megapixels).');
        }

        return [(int) $info[0], (int) $info[1], (int) $info[2]];
    }

    /**
     * Decodifica (de fato) e aplica a orientacao.
     *
     * @throws InvalidImage
     */
    public function load(string $path): GdImage
    {
        [, , $tipo] = $this->inspect($path);
        $this->ensureMemory();
        $img = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if (! $img instanceof GdImage) {
            throw new InvalidImage('Não foi possível ler a imagem (arquivo corrompido ou disfarçado).');
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        if ($tipo === IMAGETYPE_JPEG) {
            $img = $this->orient($img, self::exifOrientation($path));
        }

        return $img;
    }

    /**
     * Tamanho final sem passar de $maxWidth (nunca aumenta).
     *
     * @return array{0: int, 1: int}
     */
    public function fit(GdImage $img, int $maxWidth): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= $maxWidth) {
            return [$w, $h];
        }

        return [$maxWidth, max(1, (int) round($h * $maxWidth / $w))];
    }

    /** Copia redimensionada, em WebP. */
    public function webp(GdImage $img, int $width, int $height): string
    {
        $alvo = imagecreatetruecolor($width, $height);
        imagealphablending($alvo, false);
        imagesavealpha($alvo, true);
        imagefill($alvo, 0, 0, (int) imagecolorallocatealpha($alvo, 0, 0, 0, 127));
        imagecopyresampled($alvo, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));
        ob_start();
        imagewebp($alvo, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($alvo);
        if ($bytes === '') {
            throw new InvalidImage('Não foi possível gerar a imagem.');
        }

        return $bytes;
    }

    private function orient(GdImage $img, int $orientation): GdImage
    {
        $girada = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($girada instanceof GdImage) {
            imagedestroy($img);

            return $girada;
        }

        return $img;
    }

    /**
     * Orientacao EXIF (tag 0x0112) lida direto do segmento APP1 do JPEG, sem
     * depender da extensao exif. 1 = normal; 3, 6, 8 = giros.
     */
    public static function exifOrientation(string $path): int
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return 1;
        }
        $dados = (string) fread($f, 131072);
        fclose($f);
        if (! str_starts_with($dados, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $tam = strlen($dados);
        while ($pos + 4 <= $tam && $dados[$pos] === "\xFF") {
            $marcador = ord($dados[$pos + 1]);
            $seg = (ord($dados[$pos + 2]) << 8) + ord($dados[$pos + 3]);
            if ($marcador === 0xE1 && substr($dados, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($dados, $pos + 10, $seg - 8));
            }
            if ($marcador === 0xDA || $seg < 2) {
                break; // inicio da imagem: nao ha mais metadados
            }
            $pos += 2 + $seg;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $le = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $o): int => strlen($tiff) >= $o + 2 ? (int) unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = fn (int $o): int => strlen($tiff) >= $o + 4 ? (int) unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
        $ifd = $u32(4);
        $n = $u16($ifd);
        for ($i = 0; $i < $n && $i < 200; $i++) {
            $e = $ifd + 2 + $i * 12;
            if ($u16($e) === 0x0112) {
                $v = $u16($e + 8);

                return in_array($v, [1, 2, 3, 4, 5, 6, 7, 8], true) ? $v : 1;
            }
        }

        return 1;
    }

    private function ensureMemory(): void
    {
        $atual = (string) ini_get('memory_limit');
        if ($atual !== '-1' && self::bytes($atual) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }
}

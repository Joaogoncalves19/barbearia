<?php

namespace App\Modules\Shared\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagens publicas (fotos de profissionais, imagens de servicos e do site;
 * imagens.md). Ficam no disco de midia configuravel
 * (config('barbearia.media_disk'), padrao "public"), nunca no banco e nunca
 * no Git: o banco guarda so o caminho relativo.
 *
 * Fase 11: o arquivo enviado NUNCA e publicado como veio. Ele e decodificado
 * e reprocessado no servidor (ImageProcessor): sai sempre WebP, em tamanhos
 * limitados (variantes para srcset), sem metadados (EXIF/GPS) e com a
 * orientacao aplicada. Nome sempre gerado; a extensao e sempre .webp (um
 * arquivo enviado nunca vira script executavel no servidor).
 *
 * Caminho gravado: "{pasta}/{id}.{L}x{A}.webp" (a maior versao, com as
 * dimensoes no nome, para width/height na tela sem consultar o disco);
 * variantes menores: "{pasta}/{id}.{l}w.webp". Caminhos antigos (antes da
 * Fase 11) continuam funcionando, sem variantes.
 */
final class ImageStore
{
    /**
     * Regras de validacao de upload, usadas por todos os formularios.
     * O tipo e conferido pelo CONTEUDO (MIME real) e pela decodificacao.
     */
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000', new DecodableImage];
    }

    /** Larguras das variantes (srcset). */
    public const WIDTHS = [480, 960, 1600, 2400];

    /** Maior largura gravada por uso. */
    public const PROFILES = ['hero' => 2400, 'about' => 1600, 'gallery' => 1600, 'card' => 1200, 'portrait' => 1200, 'logo' => 600];

    public static function disk(): string
    {
        return (string) config('barbearia.media_disk', 'public');
    }

    /**
     * Reprocessa e grava a imagem (variantes incluidas).
     *
     * @throws InvalidImage
     */
    public static function store(UploadedFile $file, string $folder, string $profile = 'card'): StoredImage
    {
        $max = self::PROFILES[$profile] ?? 1200;
        $base = $folder.'/'.strtolower((string) Str::ulid());
        $processor = new ImageProcessor;
        $img = $processor->load((string) $file->getRealPath());

        try {
            [$largura, $altura] = $processor->fit($img, $max);
            $principal = $base.'.'.$largura.'x'.$altura.'.webp';
            self::put($principal, $processor->webp($img, $largura, $altura));
            foreach (self::WIDTHS as $w) {
                if ($w < $largura) {
                    self::put($base.'.'.$w.'w.webp', $processor->webp($img, $w, (int) round($altura * $w / $largura)));
                }
            }
        } finally {
            imagedestroy($img);
        }

        return new StoredImage($principal, $largura, $altura);
    }

    /** Grava a nova imagem e apaga a anterior (se houver). Devolve o caminho. */
    public static function replace(UploadedFile $file, string $folder, ?string $old, string $profile = 'card'): string
    {
        $path = self::store($file, $folder, $profile)->path;
        self::delete($old);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if (! self::safe($path)) {
            return;
        }
        $disco = Storage::disk(self::disk());
        $disco->delete((string) $path);
        $partes = self::parse((string) $path);
        if ($partes !== null) {
            foreach (self::WIDTHS as $w) {
                $disco->delete($partes['base'].'.'.$w.'w.webp');
            }
        }
    }

    public static function url(?string $path): ?string
    {
        return self::safe($path) ? Storage::disk(self::disk())->url((string) $path) : null;
    }

    /** "url 480w, url 960w, ..." (vazio para caminhos antigos, sem variantes). */
    public static function srcset(?string $path): string
    {
        $partes = self::safe($path) ? self::parse((string) $path) : null;
        if ($partes === null) {
            return '';
        }
        $disco = Storage::disk(self::disk());
        $itens = [];
        foreach (self::WIDTHS as $w) {
            if ($w < $partes['width']) {
                $itens[] = $disco->url($partes['base'].'.'.$w.'w.webp').' '.$w.'w';
            }
        }
        $itens[] = $disco->url((string) $path).' '.$partes['width'].'w';

        return implode(', ', $itens);
    }

    /**
     * @return array{0: int, 1: int}|null largura e altura da maior versao
     */
    public static function dimensions(?string $path): ?array
    {
        $partes = self::safe($path) ? self::parse((string) $path) : null;

        return $partes !== null ? [$partes['width'], $partes['height']] : null;
    }

    /**
     * @return array{base: string, width: int, height: int}|null
     */
    private static function parse(string $path): ?array
    {
        if (preg_match('#^(?<base>[a-z0-9_-]+(?:/[a-z0-9_-]+)*/[0-9a-z]{26})\.(?<w>\d{1,4})x(?<h>\d{1,4})\.webp$#', $path, $m) !== 1) {
            return null;
        }

        return ['base' => $m['base'], 'width' => (int) $m['w'], 'height' => (int) $m['h']];
    }

    private static function safe(?string $path): bool
    {
        return $path !== null && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/');
    }

    private static function put(string $path, string $bytes): void
    {
        if (! Storage::disk(self::disk())->put($path, $bytes)) {
            throw new \RuntimeException('Nao foi possivel gravar a imagem.');
        }
    }
}

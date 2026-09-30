<?php

namespace App\Modules\Shared\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Imagens publicas (fotos de profissionais, imagens de servicos e, no
 * futuro, do site). Ficam no disco de midia configuravel
 * (config('barbearia.media_disk'), padrao "public"), nunca no banco e nunca
 * no Git: o banco guarda so o caminho relativo.
 *
 * Nome do arquivo sempre gerado (hash), nunca o enviado pelo usuario.
 * Formatos aceitos: JPEG, PNG e WebP (SVG nao: pode conter script).
 */
final class ImageStore
{
    /** Regras de validacao de upload, usadas por todos os formularios. */
    public const RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000'];

    public static function disk(): string
    {
        return (string) config('barbearia.media_disk', 'public');
    }

    /** Grava a nova imagem e apaga a anterior (se houver). Devolve o caminho. */
    public static function replace(UploadedFile $file, string $folder, ?string $old): string
    {
        $path = $file->store($folder, self::disk());

        if ($path === false) {
            throw new \RuntimeException('Nao foi possivel gravar a imagem.');
        }

        self::delete($old);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && ! str_contains($path, '..')) {
            Storage::disk(self::disk())->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }
}

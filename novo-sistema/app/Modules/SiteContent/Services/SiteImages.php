<?php

namespace App\Modules\SiteContent\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\SiteContent\Models\SiteImage;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imagens do site (imagens.md §3): envio (reprocessado), texto alternativo,
 * ordem, ativar/desativar e remover. Tudo auditado. Remover apaga o arquivo
 * (e conteudo do site, nao historico do negocio).
 */
final class SiteImages
{
    /**
     * @throws InvalidArgumentException
     */
    public function upload(string $kind, UploadedFile $file, string $alt, ?string $caption, User $actor): SiteImage
    {
        $def = SiteImage::KINDS[$kind] ?? throw new InvalidArgumentException('Tipo de imagem inválido.');
        if (SiteImage::query()->where('kind', $kind)->count() >= $def['max']) {
            throw new InvalidArgumentException('Limite de '.$def['max'].' imagem(ns) em "'.$def['label'].'". Remova uma antes.');
        }
        $gravada = ImageStore::store($file, 'site/'.$kind, $def['profile']);

        try {
            return DB::transaction(function () use ($kind, $gravada, $alt, $caption, $actor): SiteImage {
                $img = SiteImage::query()->create([
                    'kind' => $kind, 'path' => $gravada->path, 'alt' => self::text($alt, 160), 'caption' => self::text((string) $caption, 160) ?: null,
                    'width' => $gravada->width, 'height' => $gravada->height,
                    'sort_order' => (int) SiteImage::query()->where('kind', $kind)->max('sort_order') + 1,
                    'uploaded_by_user_id' => $actor->id,
                ]);
                AuditTrail::record('site.image_uploaded', $img, $actor, 'Imagem enviada ao site ('.$img->kindLabel().').', ['tipo' => $kind]);

                return $img;
            });
        } catch (\Throwable $e) {
            ImageStore::delete($gravada->path); // nada de arquivo orfao

            throw $e;
        }
    }

    public function update(SiteImage $image, string $alt, ?string $caption, bool $active, User $actor): SiteImage
    {
        $image->forceFill(['alt' => self::text($alt, 160), 'caption' => self::text((string) $caption, 160) ?: null, 'is_active' => $active])->save();
        AuditTrail::record('site.image_updated', $image, $actor, 'Imagem do site alterada ('.$image->kindLabel().').', ['ativa' => $active ? 'sim' : 'nao']);

        return $image;
    }

    /** Sobe ou desce uma posicao dentro do mesmo tipo. */
    public function move(SiteImage $image, int $direction, User $actor): void
    {
        DB::transaction(function () use ($image, $direction, $actor): void {
            $lista = SiteImage::query()->where('kind', $image->kind)->orderBy('sort_order')->orderBy('id')->get()->values();
            $i = $lista->search(fn (SiteImage $x) => $x->id === $image->id);
            $j = is_int($i) ? $i + ($direction < 0 ? -1 : 1) : -1;
            if (! is_int($i) || $j < 0 || $j >= $lista->count()) {
                return;
            }
            $tmp = $lista[$i];
            $lista[$i] = $lista[$j];
            $lista[$j] = $tmp;
            foreach ($lista as $pos => $img) {
                $img->forceFill(['sort_order' => $pos + 1])->save();
            }
            AuditTrail::record('site.image_moved', $image, $actor, 'Ordem das imagens do site alterada ('.$image->kindLabel().').');
        });
    }

    public function delete(SiteImage $image, User $actor): void
    {
        $caminho = $image->path;
        DB::transaction(function () use ($image, $actor): void {
            AuditTrail::record('site.image_deleted', $image, $actor, 'Imagem removida do site ('.$image->kindLabel().').', ['tipo' => $image->kind]);
            $image->delete();
        });
        ImageStore::delete($caminho);
    }

    private static function text(string $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\p{C}+/u', ' ', $text)), 0, $max);
    }
}

<?php

namespace App\Modules\SiteContent\Models;

use App\Modules\Shared\Media\ImageStore;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Imagem do site (imagens.md): inicio (hero), "a barbearia" (about),
 * galeria e logo. Arquivo sempre reprocessado (ImageStore); texto
 * alternativo obrigatorio (acessibilidade e SEO).
 *
 * @property int $id
 * @property string $kind
 * @property string $path
 * @property string $alt
 * @property string|null $caption
 * @property int $width
 * @property int $height
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $uploaded_by_user_id
 * @property CarbonInterface|null $created_at
 */
class SiteImage extends Model
{
    /** Tipos, rotulo, perfil de tamanho e quantidade maxima. */
    public const KINDS = [
        'hero' => ['label' => 'Início (foto principal)', 'profile' => 'hero', 'max' => 3],
        'about' => ['label' => 'A barbearia (ambiente)', 'profile' => 'about', 'max' => 2],
        'gallery' => ['label' => 'Galeria de trabalhos', 'profile' => 'gallery', 'max' => 24],
        'logo' => ['label' => 'Logo', 'profile' => 'logo', 'max' => 1],
    ];

    protected $table = 'site_images';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $q
     */
    public function scopeShown(Builder $q, string $kind): void
    {
        $q->where('kind', $kind)->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function url(): ?string
    {
        return ImageStore::url($this->path);
    }

    public function srcset(): string
    {
        return ImageStore::srcset($this->path);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind]['label'] ?? $this->kind;
    }
}

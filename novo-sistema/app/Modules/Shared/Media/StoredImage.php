<?php

namespace App\Modules\Shared\Media;

/** Imagem gravada: caminho da maior versao e suas dimensoes. */
final class StoredImage
{
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
    ) {}
}

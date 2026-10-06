<?php

namespace App\Modules\Shared\Media;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validacao: o CONTEUDO do arquivo e JPEG, PNG ou WebP (MIME real, nao a
 * extensao nem o tipo informado pelo navegador) e o servidor consegue
 * decodifica-lo. Arquivo "poliglota" (cabecalho de imagem + script), SVG ou
 * imagem corrompida e recusado.
 */
final class DecodableImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie uma imagem JPG, PNG ou WebP.');

            return;
        }
        try {
            (new ImageProcessor)->inspect((string) $value->getRealPath());
        } catch (InvalidImage $e) {
            $fail($e->getMessage());
        }
    }
}

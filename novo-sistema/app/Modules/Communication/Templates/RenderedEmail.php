<?php

namespace App\Modules\Communication\Templates;

/** E-mail montado: assunto, view e dados (escapados pelo Blade). */
final class RenderedEmail
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $view,
        public readonly array $data,
        public readonly ?string $unsubscribeUrl = null,
    ) {}
}

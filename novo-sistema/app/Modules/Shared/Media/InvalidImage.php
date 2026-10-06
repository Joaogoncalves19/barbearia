<?php

namespace App\Modules\Shared\Media;

use RuntimeException;

/** Arquivo que nao e uma imagem JPEG/PNG/WebP valida (ou grande demais para processar). */
final class InvalidImage extends RuntimeException {}

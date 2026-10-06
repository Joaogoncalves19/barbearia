<?php

namespace App\Modules\SiteContent\Support;

use App\Modules\SiteContent\Models\SiteImage;

/**
 * Marca usada pelos layouts do site, do agendamento e da conta do cliente:
 * o nome configurado (ou o do sistema) e o logo enviado no painel (se houver).
 * Lido uma vez por requisicao.
 */
final class Brand
{
    /** @var array{name: string, logo: array{url: string, width: int|null, height: int|null}|null}|null */
    private static ?array $cache = null;

    /**
     * @return array{name: string, logo: array{url: string, width: int|null, height: int|null}|null}
     */
    public static function current(): array
    {
        if (self::$cache !== null && ! app()->runningUnitTests()) {
            return self::$cache;
        }
        $logo = SiteImage::query()->shown('logo')->first();
        $url = $logo?->url();

        return self::$cache = [
            'name' => SiteSettings::current()->name(),
            'logo' => $logo !== null && $url !== null ? ['url' => $url, 'width' => $logo->width, 'height' => $logo->height] : null,
        ];
    }
}

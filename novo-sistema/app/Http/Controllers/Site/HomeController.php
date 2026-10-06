<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\SiteContent\Services\PublicSite;
use App\Modules\SiteContent\Support\StructuredData;
use Illuminate\View\View;

/**
 * Inicio do site publico (Fase 11; home.md). So leitura, a partir do
 * conteudo real (PublicSite): nenhum dado ficticio. As paginas de referencia
 * da Fase 1 continuam em /prototipos (so com a flag ligada).
 */
class HomeController extends Controller
{
    public function __invoke(PublicSite $site): View
    {
        $horas = $site->hours();

        return view('site.home', [
            'cfg' => $site->settings(),
            'hero' => $site->image('hero'),
            'about' => $site->images('about'),
            'gallery' => $site->gallery(),
            'services' => $site->featuredServices(),
            'plans' => $site->plans(),
            'team' => $site->team(),
            'reviews' => $site->featuredReviews(),
            'rating' => $site->rating(),
            'hours' => $horas,
            'status' => $horas->status(),
            'jsonLd' => StructuredData::encode(StructuredData::barberShop($site)),
        ]);
    }
}

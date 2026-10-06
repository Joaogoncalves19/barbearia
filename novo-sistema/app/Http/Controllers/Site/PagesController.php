<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\SiteContent\Services\PublicSite;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Paginas publicas do site (Fase 11): servicos, equipe, profissional,
 * assinatura, paginas legais, sitemap e robots. So leitura; o que nao esta
 * publicado (servico inativo, profissional fora do site, plano inativo,
 * texto legal vazio) responde 404.
 */
class PagesController extends Controller
{
    public function __construct(private readonly PublicSite $site) {}

    public function services(): View
    {
        return view('site.services', ['cfg' => $this->site->settings(), 'groups' => $this->site->services()]);
    }

    public function team(): View
    {
        return view('site.team', ['cfg' => $this->site->settings(), 'team' => $this->site->team()]);
    }

    public function professional(string $professional): View
    {
        $pro = $this->site->professional($professional);
        abort_if($pro === null, 404);

        return view('site.professional', ['cfg' => $this->site->settings(), 'pro' => $pro, 'services' => $this->site->servicesOf($pro)]);
    }

    public function plans(): View
    {
        $planos = $this->site->plans();
        abort_if($planos->isEmpty(), 404);

        return view('site.plans', ['cfg' => $this->site->settings(), 'plans' => $planos, 'onlineSignup' => $this->site->onlineSignup()]);
    }

    public function privacy(): View
    {
        return $this->legal('privacy_policy', 'Política de privacidade');
    }

    public function terms(): View
    {
        return $this->legal('terms', 'Termos de uso');
    }

    public function sitemap(): Response
    {
        $urls = [route('home'), route('site.services')];
        if ($this->site->team()->isNotEmpty()) {
            $urls[] = route('site.team');
            foreach ($this->site->team() as $p) {
                $urls[] = route('site.professional', $p);
            }
        }
        if ($this->site->plans()->isNotEmpty()) {
            $urls[] = route('site.plans');
        }
        $urls[] = route('booking.services');
        $cfg = $this->site->settings();
        if ($cfg->has('privacy_policy')) {
            $urls[] = route('site.privacy');
        }
        if ($cfg->has('terms')) {
            $urls[] = route('site.terms');
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u, ENT_XML1).'</loc></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** robots.txt: so o site publico e indexavel; areas logadas e links pessoais, nunca. */
    public function robots(): Response
    {
        $indexavel = app()->environment('production');
        $linhas = ['User-agent: *'];
        if (! $indexavel) {
            // Homologacao/local: nada indexado (evita copia de teste no buscador).
            $linhas[] = 'Disallow: /';
        } else {
            foreach (['/painel', '/minha-conta', '/entrar', '/cadastro', '/esqueci-a-senha', '/redefinir-senha', '/confirmar-email', '/presenca', '/descadastro', '/prototipos', '/design-system', '/webhooks'] as $p) {
                $linhas[] = 'Disallow: '.$p;
            }
            $linhas[] = 'Sitemap: '.route('sitemap');
        }

        return response(implode("\n", $linhas)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function legal(string $field, string $heading): View
    {
        $cfg = $this->site->settings();
        abort_unless($cfg->has($field), 404);

        return view('site.legal', ['cfg' => $cfg, 'heading' => $heading, 'blocks' => $cfg->blocks($field)]);
    }
}

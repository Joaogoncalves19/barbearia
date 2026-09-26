<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * O site publico definitivo e a Fase 11. Ate la, onde os prototipos estao
     * ligados a raiz leva a home de referencia; nos demais ambientes mostra
     * uma pagina neutra "em construcao" (sem dado ficticio).
     */
    public function __invoke(): View|RedirectResponse
    {
        if (config('barbearia.prototypes_enabled')) {
            return redirect()->route('prototypes.home');
        }

        return view('site.coming-soon');
    }
}

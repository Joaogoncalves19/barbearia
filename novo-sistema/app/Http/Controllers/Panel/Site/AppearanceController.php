<?php

namespace App\Http\Controllers\Panel\Site;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\SiteContent\Support\Appearance;
use App\Modules\SiteContent\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuracoes > Aparencia (temas-visuais.md): o proprietario escolhe um dos
 * temas predefinidos. A previa mostra o painel inteiro no tema escolhido SO
 * nesta requisicao (nada e gravado); "Usar este tema" grava e vale para o
 * site, a conta do cliente e o painel de todo mundo.
 */
class AppearanceController extends Controller
{
    public function index(): View
    {
        return view('panel.appearance.index', [
            'themes' => Theme::all(),
            'current' => Appearance::current()->theme(),
        ]);
    }

    public function preview(Request $request, string $theme): View
    {
        $tema = Theme::find($theme);
        abort_if($tema === null, 404);
        $request->attributes->set(Appearance::PREVIEW_ATTRIBUTE, $tema->key);

        return view('panel.appearance.preview', [
            'theme' => $tema,
            'current' => Appearance::current()->theme(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate(['theme' => ['required', 'string', 'max:40']], [], ['theme' => 'tema']);
        /** @var User $user */
        $user = $request->user();
        $tema = Appearance::chooseTheme($dados['theme'], $user);

        return redirect()->route('panel.appearance')->with('status', 'Tema '.$tema->name().' aplicado ao site, à conta dos clientes e ao painel.');
    }
}

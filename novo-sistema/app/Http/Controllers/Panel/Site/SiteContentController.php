<?php

namespace App\Http\Controllers\Panel\Site;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Media\InvalidImage;
use App\Modules\SiteContent\Models\SiteImage;
use App\Modules\SiteContent\Services\SiteImages;
use App\Modules\SiteContent\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Conteudo do site no painel (site-publico.md §3; site.manage): textos,
 * contatos, paginas legais e imagens. Servicos e equipe NAO sao editados aqui:
 * o site le o cadastro do catalogo e da equipe (sem cadastro paralelo).
 */
class SiteContentController extends Controller
{
    public function edit(): View
    {
        return view('panel.site.content', ['settings' => SiteSettings::current()->toArray(), 'fields' => SiteSettings::FIELDS]);
    }

    public function update(Request $request): RedirectResponse
    {
        SiteSettings::save($request->only(array_keys(SiteSettings::FIELDS)), $this->user($request));

        return redirect()->route('panel.site.content')->with('status', 'Conteúdo do site salvo.');
    }

    public function images(): View
    {
        return view('panel.site.images', [
            'groups' => collect(SiteImage::KINDS)->map(fn ($def, $kind) => [
                'kind' => $kind, 'def' => $def,
                'images' => SiteImage::query()->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get(),
            ])->values(),
        ]);
    }

    public function storeImage(Request $request, SiteImages $images): RedirectResponse
    {
        $dados = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SiteImage::KINDS))],
            'image' => ['required', 'file', ...ImageStore::rules()],
            'alt' => ['required', 'string', 'min:3', 'max:160'],
            'caption' => ['nullable', 'string', 'max:160'],
        ], [], ['kind' => 'tipo', 'image' => 'imagem', 'alt' => 'descrição da imagem', 'caption' => 'legenda']);

        try {
            $images->upload($dados['kind'], $request->file('image'), $dados['alt'], $dados['caption'] ?? null, $this->user($request));
        } catch (InvalidArgumentException|InvalidImage $e) {
            return back()->withInput()->withErrors(['image' => $e->getMessage()]);
        }

        return back()->with('status', 'Imagem enviada e otimizada para o site.');
    }

    public function updateImage(Request $request, SiteImage $image, SiteImages $images): RedirectResponse
    {
        $dados = $request->validate([
            'alt' => ['required', 'string', 'min:3', 'max:160'],
            'caption' => ['nullable', 'string', 'max:160'],
        ], [], ['alt' => 'descrição da imagem', 'caption' => 'legenda']);
        $images->update($image, $dados['alt'], $dados['caption'] ?? null, $request->boolean('is_active'), $this->user($request));

        return back()->with('status', 'Imagem atualizada.');
    }

    public function moveImage(Request $request, SiteImage $image, SiteImages $images): RedirectResponse
    {
        $images->move($image, $request->input('direction') === 'up' ? -1 : 1, $this->user($request));

        return back()->with('status', 'Ordem atualizada.');
    }

    public function destroyImage(Request $request, SiteImage $image, SiteImages $images): RedirectResponse
    {
        $images->delete($image, $this->user($request));

        return back()->with('status', 'Imagem removida do site.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

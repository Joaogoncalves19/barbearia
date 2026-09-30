<?php

namespace App\Http\Controllers\Panel\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\CategoryRequest;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\CategoryAdmin;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Ordering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Categorias do catalogo. Autorizacao na rota (can:services.*); regras no
 * CategoryAdmin.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryAdmin $admin) {}

    public function index(): View
    {
        return view('panel.catalog.categories.index', [
            'categories' => ServiceCategory::query()->ordered()->withCount([
                'services',
                'services as active_services_count' => fn ($q) => $q->where('is_active', true),
            ])->get(),
        ]);
    }

    public function create(): View
    {
        return view('panel.catalog.categories.form', ['category' => new ServiceCategory]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $c = $this->admin->create($request->categoryData());

        return redirect()->route('panel.categories.index')->with('status', "Categoria \"{$c->name}\" criada.");
    }

    public function edit(ServiceCategory $category): View
    {
        return view('panel.catalog.categories.form', [
            'category' => $category,
            'canDelete' => $this->admin->canDelete($category),
        ]);
    }

    public function update(CategoryRequest $request, ServiceCategory $category): RedirectResponse
    {
        try {
            $this->admin->update($category, $request->categoryData(), $request->integer('version'));
        } catch (StaleRecord) {
            return back()->withInput()->withErrors(['version' => self::STALE]);
        }

        return redirect()->route('panel.categories.index')->with('status', 'Categoria atualizada.');
    }

    public function status(Request $request, ServiceCategory $category): RedirectResponse
    {
        $ativo = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $this->admin->setActive($category, $ativo);

        return back()->with('status', $ativo
            ? "Categoria \"{$category->name}\" ativada."
            : "Categoria \"{$category->name}\" desativada: os serviços dela saem dos novos agendamentos. Nada do histórico muda.");
    }

    public function move(Request $request, ServiceCategory $category): RedirectResponse
    {
        $dados = $request->validate(['direction' => ['required', Rule::in([Ordering::UP, Ordering::DOWN])]]);
        $this->admin->move($category, $dados['direction']);

        return back()->with('status', 'Ordem atualizada.');
    }

    public function destroy(ServiceCategory $category): RedirectResponse
    {
        try {
            $this->admin->delete($category);
        } catch (DomainRuleViolation) {
            return back()->withErrors(['category' => 'Esta categoria já teve serviços e não pode ser excluída. Desative-a.']);
        }

        return redirect()->route('panel.categories.index')->with('status', "Categoria \"{$category->name}\" excluída.");
    }

    public const STALE = 'Outra pessoa alterou este cadastro enquanto você editava. Nada foi salvo: confira os dados atuais e tente de novo.';
}

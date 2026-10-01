<?php

namespace App\Http\Controllers\Panel\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductAdmin;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Produtos (produtos.md). Cadastro so pelo ProductAdmin; o saldo aparece na
 * lista, mas so muda por movimentacao (StockController). Autorizacao na
 * rota: products.view/create/update/toggle.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductAdmin $admin) {}

    public function index(Request $request, StockLedger $stock): View
    {
        $filtro = $request->query('situacao', 'ativos');
        $filtro = in_array($filtro, ['ativos', 'inativos', 'baixo', 'todos'], true) ? $filtro : 'ativos';

        $produtos = Product::query()
            ->when($filtro === 'ativos' || $filtro === 'baixo', fn ($q) => $q->where('is_active', true))
            ->when($filtro === 'inativos', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->get();
        $saldos = $stock->balances($produtos->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($filtro === 'baixo') {
            $produtos = $produtos->filter(fn (Product $p) => StockLedger::situation($p, $saldos[$p->id] ?? 0) !== 'ok')->values();
        }

        return view('panel.catalog.products.index', ['products' => $produtos, 'balances' => $saldos, 'filter' => $filtro]);
    }

    public function create(): View
    {
        return view('panel.catalog.products.form', ['product' => new Product(['unit' => 'un', 'is_active' => true]), 'canDelete' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $p = $this->admin->create($this->validated($request, null) + ['is_active' => true]);

        return redirect()->route('panel.stock.show', $p)->with('status', "Produto \"{$p->name}\" cadastrado. Registre a entrada do estoque inicial.");
    }

    public function edit(Product $product): View
    {
        return view('panel.catalog.products.form', ['product' => $product, 'canDelete' => $this->admin->canDelete($product)]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $dados = $this->validated($request, $product);
        try {
            $this->admin->update($product, $dados, $request->integer('version'));
        } catch (StaleRecord) {
            return back()->withInput()->withErrors(['version' => CategoryController::STALE]);
        }

        return redirect()->route('panel.products.index')->with('status', 'Produto atualizado. Vendas e consumos já feitos mantêm o valor registrado.');
    }

    public function setStatus(Request $request, Product $product): RedirectResponse
    {
        $ativo = $request->boolean('active');
        $this->admin->setActive($product, $ativo);

        return back()->with('status', $ativo ? "Produto \"{$product->name}\" ativado." : "Produto \"{$product->name}\" desativado. O histórico não muda.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->admin->delete($product);
        } catch (DomainRuleViolation) {
            return back()->withErrors(['product' => 'Produto com histórico não pode ser excluído: desative-o.']);
        }

        return redirect()->route('panel.products.index')->with('status', "Produto \"{$product->name}\" excluído.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product): array
    {
        $dinheiro = function (string $attribute, mixed $value, \Closure $fail): void {
            $m = Money::tryParse((string) $value);
            if ($m === null || $m->isNegative() || $m->cents > Product::MAX_PRICE_CENTS) {
                $fail('Informe um valor entre R$ 0,00 e '.Money::fromCents(Product::MAX_PRICE_CENTS)->format().' (ex.: 35,00).');
            }
        };

        $dados = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('products', 'name')->ignore($product?->id)->whereNull('deleted_at')],
            'sku' => ['nullable', 'string', 'max:32', Rule::unique('products', 'sku')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', Rule::in(array_keys(Product::UNITS))],
            'price' => ['nullable', 'string', 'max:20', $dinheiro],
            'cost' => ['nullable', 'string', 'max:20', $dinheiro],
            'min_stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['name.unique' => 'Já existe um produto com este nome.', 'sku.unique' => 'Este código já está em uso.'],
            ['name' => 'nome', 'sku' => 'código', 'description' => 'descrição', 'unit' => 'unidade', 'price' => 'preço de venda', 'cost' => 'custo', 'min_stock' => 'estoque mínimo']);

        return [
            'name' => trim($dados['name']),
            'sku' => isset($dados['sku']) && trim($dados['sku']) !== '' ? mb_strtoupper(trim($dados['sku'])) : null,
            'description' => $dados['description'] ?? null,
            'unit' => $dados['unit'],
            'price_cents' => Money::tryParse($dados['price'] ?? null)?->cents,
            'cost_cents' => Money::tryParse($dados['cost'] ?? null)?->cents,
            'min_stock' => isset($dados['min_stock']) ? (int) $dados['min_stock'] : null,
        ];
    }
}

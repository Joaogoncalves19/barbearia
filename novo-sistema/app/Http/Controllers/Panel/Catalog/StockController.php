<?php

namespace App\Http\Controllers\Panel\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Estoque de um produto (estoque.md): saldo, historico e lancamentos.
 * Tudo passa pelo StockLedger. Autorizacao na rota: stock.view (ver),
 * stock.receive (entrada), stock.issue (saida/perda), stock.adjust
 * (ajuste de inventario e estorno de movimentacao).
 */
class StockController extends Controller
{
    public function __construct(private readonly StockLedger $stock) {}

    public function show(Product $product): View
    {
        $saldo = $this->stock->balance($product);

        return view('panel.catalog.products.stock', [
            'product' => $product,
            'balance' => $saldo,
            'situation' => StockLedger::situation($product, $saldo),
            'movements' => $product->stockMovements()->with(['attendance', 'reversal', 'createdBy'])->latest('id')->paginate(30),
        ]);
    }

    public function receive(Request $request, Product $product): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_cost' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['quantity' => 'quantidade', 'unit_cost' => 'custo unitário', 'reason' => 'observação']);

        $custo = null;
        if (! empty($dados['unit_cost'])) {
            $m = Money::tryParse($dados['unit_cost']);
            if ($m === null || $m->isNegative()) {
                return back()->withInput()->withErrors(['unit_cost' => 'Informe o custo unitário (ex.: 12,50).']);
            }
            $custo = $m->cents;
        }

        return $this->run(fn () => $this->stock->receive($product, (int) $dados['quantity'], $custo, $dados['reason'] ?? null, $this->user($request), $dados['request_key']),
            "Entrada de {$dados['quantity']} registrada.");
    }

    public function issue(Request $request, Product $product): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'kind' => ['required', Rule::in([StockMovementKind::Usage->value, StockMovementKind::Loss->value])],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['quantity' => 'quantidade', 'reason' => 'motivo', 'kind' => 'tipo']);

        return $this->run(fn () => $this->stock->issue($product, (int) $dados['quantity'], StockMovementKind::from($dados['kind']), $dados['reason'], $this->user($request), $dados['request_key']),
            StockMovementKind::from($dados['kind'])->label()." de {$dados['quantity']} registrada.");
    }

    public function adjust(Request $request, Product $product): RedirectResponse
    {
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'counted' => ['required', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['counted' => 'contagem física', 'reason' => 'motivo']);

        return $this->run(fn () => $this->stock->adjustTo($product, (int) $dados['counted'], $dados['reason'], $this->user($request), $dados['request_key']),
            "Inventário ajustado para {$dados['counted']}.");
    }

    public function reverse(Request $request, Product $product, StockMovement $movement): RedirectResponse
    {
        abort_unless($movement->product_id === $product->id, 404);
        $dados = $request->validate([
            'request_key' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['reason' => 'motivo']);

        if ($movement->attendance_id !== null) {
            // Venda/consumo de atendimento: devolve-se pelo atendimento (fica no historico dele).
            return back()->withErrors(['stock' => 'Esta movimentação veio de um atendimento: devolva pelo atendimento.']);
        }

        return $this->run(fn () => $this->stock->reverse($movement, $dados['reason'], $this->user($request), $dados['request_key']), 'Movimentação estornada.');
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function run(Closure $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (StockRuleViolation $e) {
            return back()->withInput()->withErrors(['stock' => $e->getMessage()]);
        }

        return back()->with('status', $ok);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('web');
    }
}

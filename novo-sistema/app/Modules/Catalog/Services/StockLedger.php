<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * O UNICO lugar que lanca movimentacoes de estoque (estoque.md). O saldo e a
 * soma das movimentacoes; nada edita um saldo.
 *
 * Regras:
 * - saldo nunca fica negativo por um lancamento novo (so o importador, que
 *   traz o legado como esta, grava sem esta checagem);
 * - toda gravacao roda numa transacao que PRIMEIRO escreve na linha do
 *   produto (stock_version + 1) e SO DEPOIS le o saldo: dois lancamentos
 *   simultaneos no mesmo produto ficam em fila e o segundo ve o saldo ja
 *   atualizado (mesma protecao da agenda, agendamento.md §5);
 * - varios produtos na mesma transacao sao travados em ordem crescente de id;
 * - request_key (chave do formulario) torna o lancamento manual idempotente:
 *   repetir a requisicao devolve o movimento ja gravado.
 */
final class StockLedger
{
    /** Gancho SO para o teste de concorrencia (entre a leitura do saldo e a gravacao). */
    public static ?Closure $afterCheck = null;

    public function balance(Product|int $product): int
    {
        $id = $product instanceof Product ? $product->getKey() : $product;

        return (int) StockMovement::query()->where('product_id', $id)->sum('quantity');
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> saldo por produto
     */
    public function balances(array $ids): array
    {
        $saldos = array_fill_keys($ids, 0);
        foreach (StockMovement::query()->whereIn('product_id', $ids)->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as saldo')->get() as $linha) {
            $saldos[(int) $linha->getAttribute('product_id')] = (int) $linha->getAttribute('saldo');
        }

        return $saldos;
    }

    /** Situacao frente ao estoque minimo: ok, low (no minimo ou abaixo) ou out (zerado). */
    public static function situation(Product $product, int $balance): string
    {
        return match (true) {
            $balance <= 0 => 'out',
            $product->min_stock !== null && $balance <= $product->min_stock => 'low',
            default => 'ok',
        };
    }

    /** Entrada (compra/reposicao). */
    public function receive(Product $product, int $quantity, ?int $unitCostCents, ?string $reason, User $actor, ?string $key = null): StockMovement
    {
        $this->assertQuantity($quantity);

        return $this->transaction($product, $key, $actor, function (Product $p, array $meta) use ($quantity, $unitCostCents, $reason, $actor): StockMovement {
            if (! $p->is_active) {
                throw new StockRuleViolation('inactive_product', $p->name);
            }

            return $this->write($p, $quantity, StockMovementKind::Purchase, $reason, $actor, ['unit_cost_cents' => $unitCostCents, ...$meta]);
        });
    }

    /** Saida manual: uso interno/outra saida (Usage) ou perda (Loss). Motivo obrigatorio. */
    public function issue(Product $product, int $quantity, StockMovementKind $kind, string $reason, User $actor, ?string $key = null): StockMovement
    {
        $this->assertQuantity($quantity);
        $this->assertReason($reason);
        if (! in_array($kind, [StockMovementKind::Usage, StockMovementKind::Loss], true)) {
            throw new StockRuleViolation('not_reversible');
        }

        return $this->transaction($product, $key, $actor, fn (Product $p, array $meta) => $this->write($p, -$quantity, $kind, $reason, $actor, $meta));
    }

    /**
     * Ajuste de inventario: informa a contagem fisica; o sistema lanca a
     * diferenca (contagem - saldo). Motivo obrigatorio.
     */
    public function adjustTo(Product $product, int $counted, string $reason, User $actor, ?string $key = null): StockMovement
    {
        if ($counted < 0) {
            throw new StockRuleViolation('invalid_quantity');
        }
        $this->assertReason($reason);

        return $this->transaction($product, $key, $actor, function (Product $p, array $meta) use ($counted, $reason, $actor): StockMovement {
            $diferenca = $counted - $this->balance($p);
            if ($diferenca === 0) {
                throw new StockRuleViolation('no_change', $p->name);
            }

            return $this->write($p, $diferenca, StockMovementKind::Adjustment, $reason, $actor, $meta);
        });
    }

    /**
     * Estorna uma movimentacao: lanca a quantidade inversa, apontando a
     * original (uma vez so). O original nunca e apagado nem editado.
     */
    public function reverse(StockMovement $movement, string $reason, User $actor, ?string $key = null): StockMovement
    {
        $this->assertReason($reason);
        $product = Product::withTrashed()->findOrFail($movement->product_id);

        return $this->transaction($product, $key, $actor, function (Product $p, array $meta) use ($movement, $reason, $actor): StockMovement {
            $original = StockMovement::query()->findOrFail($movement->id);
            if (! $original->kind->isReversible()) {
                throw new StockRuleViolation('not_reversible');
            }
            if (StockMovement::query()->where('reverses_movement_id', $original->id)->exists()) {
                throw new StockRuleViolation('already_reversed');
            }

            return $this->write($p, -$original->quantity, StockMovementKind::Reversal, $reason, $actor, [
                'reverses_movement_id' => $original->id,
                'attendance_id' => $original->attendance_id,
                'unit_cost_cents' => $original->unit_cost_cents,
                ...$meta,
            ]);
        });
    }

    /**
     * Trava os produtos (ordem crescente de id). Para quem ja esta dentro de
     * uma transacao e vai lancar varios movimentos (conclusao do atendimento).
     *
     * @param  list<int>  $ids
     */
    public function lock(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($ids as $id) {
            DB::table('products')->where('id', $id)->increment('stock_version');
        }
    }

    /**
     * Lanca um movimento num produto JA travado (lock) dentro da transacao do
     * chamador. Recusa saldo negativo.
     *
     * @param  array<string, mixed>  $extra
     */
    public function write(Product $product, int $quantity, StockMovementKind $kind, ?string $reason, ?User $actor, array $extra = []): StockMovement
    {
        $saldo = $this->balance($product) + $quantity;
        if ($saldo < 0) {
            throw new StockRuleViolation('insufficient', $product->name);
        }

        if (self::$afterCheck !== null) {
            (self::$afterCheck)();
        }

        return StockMovement::query()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'kind' => $kind,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 255) : null,
            'balance_after' => $saldo,
            'created_by_user_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'occurred_at' => BusinessTime::now(),
            ...$extra,
        ]);
    }

    /**
     * @param  Closure(Product, array{request_key?: string}): StockMovement  $work
     */
    private function transaction(Product $product, ?string $key, User $actor, Closure $work): StockMovement
    {
        return DB::transaction(function () use ($product, $key, $actor, $work): StockMovement {
            $this->lock([$product->id]);

            if ($key !== null) {
                $existente = StockMovement::query()->where('request_key', $key)->first();
                if ($existente !== null) {
                    return $existente; // repeticao da mesma requisicao
                }
            }

            $p = Product::withTrashed()->findOrFail($product->id);
            $m = $work($p, $key !== null ? ['request_key' => $key] : []);

            // Lancamento manual tambem vai para a trilha de auditoria (o razao
            // ja guarda quem, quando, quanto e por que; a trilha junta com o
            // resto das alteracoes do sistema).
            AuditTrail::record('stock.'.$m->kind->value, $p, $actor, $m->kind->label().' de estoque.', [
                'quantidade' => $m->quantity, 'saldo_depois' => $m->balance_after, 'motivo' => $m->reason, 'movimento' => $m->id,
            ]);

            return $m;
        });
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new StockRuleViolation('invalid_quantity');
        }
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new StockRuleViolation('reason_required');
        }
    }
}

<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/** Estoque como razao: saldo = soma das movimentacoes. */
class StockLedger
{
    public function balance(Product|int $product): int
    {
        $id = $product instanceof Product ? $product->getKey() : $product;

        return (int) StockMovement::where('product_id', $id)->sum('quantity');
    }

    /**
     * @param  int  $quantity  com sinal (entrada > 0, saida < 0)
     */
    public function record(Product $product, int $quantity, StockMovementKind $kind, ?string $reason = null, ?int $appointmentId = null, ?string $actor = null): StockMovement
    {
        if ($quantity === 0) {
            throw DomainRuleViolation::rule('R-ESTOQUE', 'Movimentacao de estoque sem quantidade.');
        }

        return DB::transaction(function () use ($product, $quantity, $kind, $reason, $appointmentId, $actor) {
            if ($kind === StockMovementKind::Sale && $this->balance($product) + $quantity < 0) {
                throw DomainRuleViolation::rule('R-ESTOQUE', 'Estoque insuficiente para a venda.');
            }

            return StockMovement::create([
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
                'kind' => $kind,
                'reason' => $reason,
                'appointment_id' => $appointmentId,
                'actor_label' => $actor,
                'occurred_at' => now(),
            ]);
        });
    }
}

<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Exceptions\StaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de produtos: a unica porta de gravacao (produtos.md). O saldo NAO
 * e editado aqui: estoque so muda por movimentacao (StockLedger).
 *
 * Preco e custo: mudar muda so o valor ATUAL; vendas e consumos ja feitos
 * guardam o proprio valor (fotografia no item/consumo do atendimento e no
 * movimento de estoque). A auditoria guarda antes/depois.
 */
final class ProductAdmin
{
    /**
     * @param  array<string, mixed>  $data  campos ja validados
     */
    public function create(array $data): Product
    {
        return Product::query()->create($data)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, int $version): Product
    {
        return DB::transaction(function () use ($product, $data, $version): Product {
            $p = StaleRecord::guard($product, $version);
            $p->fill($data)->save();

            return $p;
        });
    }

    public function setActive(Product $product, bool $active): void
    {
        $product->is_active = $active;
        $product->save();
    }

    /** Exclusao fisica so para produto que NUNCA teve movimento, venda ou consumo. */
    public function canDelete(Product $product): bool
    {
        return ! DB::table('stock_movements')->where('product_id', $product->id)->exists()
            && ! DB::table('attendance_items')->where('product_id', $product->id)->exists()
            && ! DB::table('attendance_consumptions')->where('product_id', $product->id)->exists()
            && ! DB::table('appointment_items')->where('product_id', $product->id)->exists();
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            if (! $this->canDelete($product)) {
                throw DomainRuleViolation::rule('R-CAT', 'Produto com historico nao pode ser excluido: desative-o.');
            }
            $product->forceDelete();
        });
    }
}

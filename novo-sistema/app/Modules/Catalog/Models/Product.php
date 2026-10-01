<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produto (produtos.md): vendido ao cliente (tem preco de venda) e/ou usado
 * no servico (insumo, sem preco). NAO tem coluna de saldo: estoque = soma de
 * stock_movements (StockLedger). Cadastro so pelo ProductAdmin.
 *
 * - price_cents: preco ATUAL de venda (nulo = nao e vendido, so consumido).
 *   O valor de cada venda e fotografado no item do atendimento.
 * - cost_cents: custo unitario atual (fotografado no consumo e na venda).
 * - min_stock: estoque minimo (nulo = sem alerta).
 * - is_active: pode entrar em atendimentos novos. Inativo continua no
 *   historico e nunca e apagado se ja teve movimentacao.
 * - lock_version: edicao concorrente; stock_version: linha de trava do
 *   estoque deste produto (StockLedger).
 *
 * @property int $id
 * @property string $name
 * @property string|null $sku
 * @property string|null $description
 * @property string $unit
 * @property int|null $price_cents
 * @property int|null $cost_cents
 * @property int|null $min_stock
 * @property bool $is_active
 * @property int $lock_version
 * @property int $stock_version
 */
#[UseFactory(ProductFactory::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** Limite do preco e do custo (centavos): ate R$ 10.000,00. */
    public const MAX_PRICE_CENTS = 1_000_000;

    /** Unidades aceitas (a quantidade e sempre inteira nessa unidade). */
    public const UNITS = ['un' => 'unidade', 'cx' => 'caixa', 'fr' => 'frasco', 'pct' => 'pacote'];

    protected $table = 'products';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['lock_version', 'stock_version'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'cost_cents' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'stock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p): void {
            foreach (['price_cents' => 'Preco', 'cost_cents' => 'Custo'] as $campo => $rotulo) {
                $v = $p->getAttribute($campo);
                if ($v !== null && ($v < 0 || $v > self::MAX_PRICE_CENTS)) {
                    throw DomainRuleViolation::rule('R-DINHEIRO', "{$rotulo} do produto fora do limite.");
                }
            }
            if ($p->min_stock !== null && $p->min_stock < 0) {
                throw DomainRuleViolation::rule('R-ESTOQUE', 'Estoque minimo nao pode ser negativo.');
            }
        });
    }

    /**
     * Pode entrar em atendimentos novos.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isForSale(): bool
    {
        return $this->price_cents !== null;
    }

    public function unitLabel(): string
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}

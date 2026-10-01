<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Models\Concerns\FrozenWithAttendance;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que foi VENDIDO no atendimento (servico, combo ou produto), com nome e
 * preco FOTOGRAFADOS. Veio do agendamento (price_source catalog_at_booking:
 * o preco combinado ao agendar) ou foi acrescentado no atendimento
 * (catalog_at_attendance: o preco do catalogo naquele momento). Produto
 * vendido baixa o estoque na conclusao.
 *
 * O preco de um item nunca e editado: reduzir e desconto (com motivo e
 * permissao); cobrar mais e outro item.
 *
 * @property int $id
 * @property int $attendance_id
 * @property ItemType $item_type
 * @property int|null $service_id
 * @property int|null $package_id
 * @property int|null $product_id
 * @property int|null $appointment_item_id
 * @property string $name
 * @property int $quantity
 * @property int|null $unit_price_cents
 * @property int|null $total_cents
 * @property int|null $duration_minutes
 * @property PriceSource $price_source
 * @property int|null $cost_cents
 * @property int|null $added_by_user_id
 */
class AttendanceItem extends Model
{
    use Auditable, FrozenWithAttendance;

    protected $table = 'attendance_items';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'price_source' => PriceSource::class,
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'duration_minutes' => 'integer',
            'cost_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $i): void {
            $semPreco = $i->unit_price_cents === null;
            if ($semPreco !== ($i->price_source === PriceSource::LegacyUnknown)) {
                throw DomainRuleViolation::rule('R-PRECO', 'Preco nulo somente para item antigo sem valor conhecido (legacy_unknown).');
            }
            if ($i->quantity < 1) {
                throw DomainRuleViolation::rule('R-PRECO', 'Quantidade deve ser ao menos 1.');
            }
            if (! $semPreco && $i->unit_price_cents < 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Preco nao pode ser negativo.');
            }
            $i->total_cents = $semPreco ? null : $i->unit_price_cents * $i->quantity;
        });
    }

    public function isDiscountable(): bool
    {
        return $this->item_type !== ItemType::Product;
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

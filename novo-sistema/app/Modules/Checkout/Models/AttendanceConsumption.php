<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Concerns\FrozenWithAttendance;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material USADO no servico (ex.: lamina, pomada aplicada): nao e cobrado do
 * cliente, mas baixa o estoque na conclusao (movimento "consumo" vinculado
 * ao atendimento). Nome e custo unitario fotografados.
 *
 * @property int $id
 * @property int $attendance_id
 * @property int $product_id
 * @property string $product_name
 * @property int $quantity
 * @property int|null $unit_cost_cents
 * @property int|null $added_by_user_id
 */
class AttendanceConsumption extends Model
{
    use Auditable, FrozenWithAttendance;

    protected $table = 'attendance_consumptions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $c): void {
            if ($c->quantity < 1) {
                throw DomainRuleViolation::rule('R-ESTOQUE', 'Quantidade consumida deve ser ao menos 1.');
            }
        });
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

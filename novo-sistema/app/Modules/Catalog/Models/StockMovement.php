<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Razao do estoque (so inclusao). O saldo e a soma das quantidades; nada
 * guarda um saldo editavel. Cada movimento registra produto, quantidade com
 * sinal, tipo, motivo, origem (atendimento ou movimento revertido), quem
 * lancou, quando e o saldo logo depois (balance_after, para auditoria).
 * Lancamentos novos so pelo StockLedger.
 *
 * @property int $id
 * @property int $product_id
 * @property int $quantity
 * @property StockMovementKind $kind
 * @property string|null $reason
 * @property int|null $attendance_id
 * @property int|null $reverses_movement_id
 * @property int|null $balance_after
 * @property int|null $unit_cost_cents
 * @property int|null $created_by_user_id
 * @property string|null $actor_label
 * @property string|null $request_key
 * @property Carbon|null $occurred_at
 */
class StockMovement extends Model
{
    use AppendOnly;

    protected $table = 'stock_movements';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'kind' => StockMovementKind::class,
            'balance_after' => 'integer',
            'unit_cost_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $m): void {
            $sinal = $m->kind->sign();
            if ($m->quantity === 0 || ($sinal !== 0 && ($m->quantity > 0 ? 1 : -1) !== $sinal)) {
                throw DomainRuleViolation::rule('R-ESTOQUE', "Movimentacao {$m->kind->value} com quantidade invalida ({$m->quantity}).");
            }
            if ($m->kind->comesFromAttendance() && $m->attendance_id === null) {
                throw DomainRuleViolation::rule('R-ESTOQUE', 'Venda e consumo apontam o atendimento de origem.');
            }
            if (($m->kind === StockMovementKind::Reversal) !== ($m->reverses_movement_id !== null)) {
                throw DomainRuleViolation::rule('R-ESTOQUE', 'Estorno de movimentacao aponta o movimento revertido (e so ele).');
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /**
     * @return HasOne<StockMovement, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

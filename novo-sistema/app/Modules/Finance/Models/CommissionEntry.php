<?php

namespace App\Modules\Finance\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Razao de COMISSAO do profissional (so inclusao; comissoes.md §4).
 *
 * - earned: calculada na conclusao do atendimento, uma por item, com a base
 *   (valor do item depois do desconto rateado), a regra usada (id e
 *   fotografia) e o valor. Nunca recalculada.
 * - refund: estorno de pagamento reduz a comissao (valor negativo,
 *   aponta o estorno).
 * - adjustment: correcao manual com motivo e autor (positiva ou negativa).
 *
 * So o vinculo com o repasse (commission_payout_id) muda depois.
 *
 * @property int $id
 * @property int $professional_id
 * @property LedgerEntryKind $kind
 * @property int|null $attendance_id
 * @property int|null $attendance_item_id
 * @property int|null $payment_id
 * @property int|null $commission_rule_id
 * @property string|null $item_name
 * @property int|null $quantity
 * @property int $base_cents
 * @property int|null $rate_bp
 * @property int $amount_cents
 * @property array<string, mixed>|null $rule
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property string|null $request_key
 * @property int|null $commission_payout_id
 * @property Carbon|null $occurred_at
 */
class CommissionEntry extends Model
{
    use AppendOnly;

    protected $table = 'commission_entries';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $appendOnlyMutable = ['commission_payout_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LedgerEntryKind::class,
            'base_cents' => 'integer',
            'rate_bp' => 'integer',
            'amount_cents' => 'integer',
            'quantity' => 'integer',
            'rule' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $e): void {
            $e->kind ??= LedgerEntryKind::Earned;
            $e->occurred_at ??= now();
            $ok = match ($e->kind) {
                LedgerEntryKind::Earned => $e->amount_cents >= 0 && $e->base_cents >= 0,
                LedgerEntryKind::Refund => $e->amount_cents < 0 && $e->payment_id !== null && $e->attendance_id !== null,
                LedgerEntryKind::Adjustment => $e->amount_cents !== 0 && $e->reason !== null && trim($e->reason) !== '' && $e->created_by_user_id !== null,
            };
            if (! $ok) {
                throw DomainRuleViolation::rule('R-COMISSAO', 'Comissao: calculada >= 0; estorno negativo apontando o estorno; ajuste com motivo e autor.');
            }
        });
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<AttendanceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AttendanceItem::class, 'attendance_item_id');
    }

    /**
     * @return BelongsTo<CommissionPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'commission_payout_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

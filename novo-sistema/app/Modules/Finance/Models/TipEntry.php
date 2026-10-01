<?php

namespace App\Modules\Finance\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Razao de GORJETA do profissional (so inclusao; repasses.md §3). Separado
 * da comissao: gorjeta e o valor que o cliente destinou ao profissional,
 * nao uma remuneracao calculada por regra.
 *
 * - earned: uma por pagamento com gorjeta, na conclusao do atendimento,
 *   apontando o pagamento de origem;
 * - refund: parte da gorjeta devolvida num estorno (negativa, aponta o estorno);
 * - adjustment: correcao manual com motivo e autor.
 *
 * So o vinculo com o repasse muda depois.
 *
 * @property int $id
 * @property int $professional_id
 * @property int|null $attendance_id
 * @property int|null $payment_id
 * @property LedgerEntryKind $kind
 * @property int $amount_cents
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property string|null $request_key
 * @property int|null $commission_payout_id
 * @property Carbon $occurred_at
 */
class TipEntry extends Model
{
    use AppendOnly;

    protected $table = 'tip_entries';

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
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $t): void {
            $ok = match ($t->kind) {
                LedgerEntryKind::Earned => $t->amount_cents > 0 && $t->payment_id !== null && $t->attendance_id !== null,
                LedgerEntryKind::Refund => $t->amount_cents < 0 && $t->payment_id !== null && $t->attendance_id !== null,
                LedgerEntryKind::Adjustment => $t->amount_cents !== 0 && $t->reason !== null && trim($t->reason) !== '' && $t->created_by_user_id !== null,
            };
            if (! $ok) {
                throw DomainRuleViolation::rule('R-GORJETA', 'Gorjeta: positiva apontando o pagamento; estorno negativo apontando o estorno; ajuste com motivo e autor.');
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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

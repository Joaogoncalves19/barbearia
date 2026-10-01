<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lancamento de pontos (razao). Saldo = soma (LoyaltyLedger). Nunca editado.
 * Fase 8: ganho e resgate apontam o atendimento (um de cada por
 * atendimento); bonus de indicacao aponta o cliente indicado (uma vez);
 * ajuste tem autor, motivo e chave.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $points
 * @property LoyaltyEntryKind $kind
 * @property string|null $description
 * @property int|null $appointment_id
 * @property int|null $attendance_id
 * @property int|null $loyalty_redemption_id
 * @property int|null $referred_customer_id
 * @property int|null $created_by_user_id
 * @property string|null $request_key
 * @property Carbon|null $occurred_at
 */
class LoyaltyEntry extends Model
{
    use AppendOnly;

    protected $table = 'loyalty_entries';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'kind' => LoyaltyEntryKind::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function referredCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_customer_id');
    }
}

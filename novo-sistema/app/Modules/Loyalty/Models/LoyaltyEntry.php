<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lancamento de pontos (razao). Saldo = soma (LoyaltyLedger). Nunca editado.
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}

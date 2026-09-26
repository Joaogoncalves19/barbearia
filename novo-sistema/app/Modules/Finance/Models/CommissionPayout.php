<?php

namespace App\Modules\Finance\Models;

use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPayout extends Model
{
    protected $table = 'commission_payouts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tip_cents' => 'integer',
            'services_total_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_on' => 'date',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }
}

<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\GoalPeriod;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialGoal extends Model
{
    protected $table = 'financial_goals';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => GoalPeriod::class,
            'amount_cents' => 'integer',
            'effective_from' => 'date',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}

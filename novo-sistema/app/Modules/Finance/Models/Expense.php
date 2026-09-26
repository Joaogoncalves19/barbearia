<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $table = 'expenses';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'due_on' => 'date',
            'paid_on' => 'date',
            'status' => ExpenseStatus::class,
            'is_recurring' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'recurrence_parent_id');
    }
}

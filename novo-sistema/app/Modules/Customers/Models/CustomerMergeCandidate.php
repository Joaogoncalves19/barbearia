<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\MergeCandidateStatus;
use App\Modules\LegacyImport\Models\ImportRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Par de clientes suspeito de duplicidade. Nunca mesclado automaticamente
 * (estrategia-duplicidades.md).
 */
class CustomerMergeCandidate extends Model
{
    protected $table = 'customer_merge_candidates';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MergeCandidateStatus::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'duplicate_customer_id');
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}

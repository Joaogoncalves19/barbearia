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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'duplicate_customer_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}

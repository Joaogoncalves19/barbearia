<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\ConsentAction;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prova de consentimento (LGPD). So inclusao: a situacao atual fica em
 * customers.marketing_email_consent.
 */
class ConsentRecord extends Model
{
    use AppendOnly;

    protected $table = 'consent_records';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ConsentAction::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

<?php

namespace App\Modules\Reviews\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $rating
 */
class Review extends Model
{
    protected $table = 'reviews';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_featured' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return HasMany<ReviewReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ReviewReply::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $r): void {
            if ($r->rating < 1 || $r->rating > 5) {
                throw DomainRuleViolation::rule('R-AVAL', 'Nota deve ser de 1 a 5.');
            }
        });
    }
}

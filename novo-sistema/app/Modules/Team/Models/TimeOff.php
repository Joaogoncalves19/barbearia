<?php

namespace App\Modules\Team\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Enums\TimeOffKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeOff extends Model
{
    protected $table = 'time_off';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'kind' => TimeOffKind::class,
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $t): void {
            if ($t->ends_on->lt($t->starts_on)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Ausencia termina antes de comecar.');
            }
        });
    }
}

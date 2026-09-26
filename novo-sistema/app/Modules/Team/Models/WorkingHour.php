<?php

namespace App\Modules\Team\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingHour extends Model
{
    protected $table = 'working_hours';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $w): void {
            if ($w->weekday < 0 || $w->weekday > 6) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Dia da semana deve ser 0 (domingo) a 6.');
            }
            if (substr((string) $w->ends_at, 0, 5) <= substr((string) $w->starts_at, 0, 5)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Fim do expediente deve ser depois do inicio.');
            }
        });
    }
}

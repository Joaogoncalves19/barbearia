<?php

namespace App\Modules\Team\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pausa recorrente do profissional (ex.: almoco 12:00-13:00). weekday nulo =
 * todos os dias. Descontada da disponibilidade enquanto ativa.
 *
 * @property int $id
 * @property int $professional_id
 * @property int|null $weekday
 * @property string $starts_at
 * @property string $ends_at
 * @property string|null $label
 * @property bool $is_active
 */
class ScheduleBreak extends Model
{
    use Auditable;

    protected $table = 'schedule_breaks';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $b): void {
            if ($b->weekday !== null && ($b->weekday < 0 || $b->weekday > 6)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Dia da semana deve ser 0 (domingo) a 6.');
            }
            if (substr((string) $b->ends_at, 0, 5) <= substr((string) $b->starts_at, 0, 5)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Fim da pausa deve ser depois do inicio.');
            }
        });
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}

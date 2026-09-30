<?php

namespace App\Modules\Team\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expediente do profissional: UM intervalo por dia da semana (hora de
 * parede da barbearia). Pausas (almoco) ficam em ScheduleBreak, nunca como
 * um segundo intervalo: uma forma so de representar a mesma coisa
 * (horarios.md). Profissional sem nenhum expediente cadastrado segue o
 * horario de funcionamento da barbearia.
 *
 * @property int $id
 * @property int $professional_id
 * @property int $weekday
 * @property string $starts_at
 * @property string $ends_at
 */
class WorkingHour extends Model
{
    use Auditable;

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

    /**
     * @return BelongsTo<Professional, $this>
     */
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

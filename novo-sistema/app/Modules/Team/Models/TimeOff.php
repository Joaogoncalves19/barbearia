<?php

namespace App\Modules\Team\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Team\Enums\TimeOffKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Folga/ausencia de DIAS INTEIROS do profissional (ferias, atestado, folga
 * excepcional, treinamento). Datas civis da barbearia, inclusivas. Folga
 * recorrente (ex.: todo domingo) = dia sem expediente, nao uma folga.
 * Ausencia de parte do dia = bloqueio (BlockedSlot).
 *
 * @property int $id
 * @property int $professional_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property TimeOffKind $kind
 * @property string|null $reason
 * @property int|null $created_by_user_id
 */
class TimeOff extends Model
{
    use Auditable;

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

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

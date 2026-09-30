<?php

namespace App\Modules\Team\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bloqueio de agenda: intervalo (instantes UTC) que ninguem pode reservar.
 * Com profissional: so a agenda dele (reuniao, consulta medica). SEM
 * profissional: a barbearia inteira (feriado, evento, manutencao).
 * Nunca apaga nem move agendamentos existentes.
 *
 * @property int $id
 * @property int|null $professional_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 * @property int|null $created_by_user_id
 */
class BlockedSlot extends Model
{
    use Auditable;

    protected $table = 'blocked_slots';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $b): void {
            if ($b->ends_at->lte($b->starts_at)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Bloqueio deve terminar depois de comecar.');
            }
        });
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

    public function interval(): Interval
    {
        return new Interval(CarbonImmutable::instance($this->starts_at), CarbonImmutable::instance($this->ends_at));
    }
}

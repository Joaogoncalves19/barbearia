<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Horario de funcionamento da barbearia: um intervalo (hora de parede no
 * fuso da barbearia) de um dia da semana. Varios por dia sao permitidos
 * (ex.: 09-12 e 13-19); dia sem nenhum = fechado.
 *
 * @property int $id
 * @property int $weekday
 * @property string $starts_at
 * @property string $ends_at
 */
class BusinessHour extends Model
{
    use Auditable;

    protected $table = 'business_hours';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $h): void {
            if ($h->weekday < 0 || $h->weekday > 6) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Dia da semana deve ser 0 (domingo) a 6.');
            }
            if (substr($h->ends_at, 0, 5) <= substr($h->starts_at, 0, 5)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'Fechamento deve ser depois da abertura.');
            }
        });
    }
}

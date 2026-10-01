<?php

namespace App\Modules\Checkout\Models\Concerns;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Linha de um atendimento (item, consumo, desconto): pode mudar enquanto o
 * atendimento esta aberto ou em andamento; depois de concluido ou cancelado
 * e historico e nao muda mais (correcao so por estorno/reversao).
 *
 * @property int $attendance_id
 */
trait FrozenWithAttendance
{
    public static function bootFrozenWithAttendance(): void
    {
        $guard = function (self $linha): void {
            $status = DB::table('attendances')->where('id', $linha->attendance_id)->value('status');
            if ($status !== null && ! in_array($status, ['open', 'in_progress'], true)) {
                throw DomainRuleViolation::rule('R-HIST', class_basename($linha).': atendimento '.$status.' nao pode ser alterado.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }
}

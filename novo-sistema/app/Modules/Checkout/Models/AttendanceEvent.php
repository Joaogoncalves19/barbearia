<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Linha do tempo do atendimento (so inclusao): aberto, iniciado, itens,
 * desconto, concluido, cancelado, estorno, devolucao ao estoque.
 *
 * @property string $type
 * @property string $description
 * @property string|null $actor_label
 * @property array<string, mixed>|null $data
 * @property Carbon $occurred_at
 */
class AttendanceEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $table = 'attendance_events';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}

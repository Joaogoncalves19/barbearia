<?php

namespace App\Modules\Finance\Models;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comissao calculada no fechamento do atendimento, com a regra fotografada.
 * Estorno = lancamento negativo.
 */
class CommissionEntry extends Model
{
    use AppendOnly;

    protected $table = 'commission_entries';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $appendOnlyMutable = ['commission_payout_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_cents' => 'integer',
            'rate_bp' => 'integer',
            'amount_cents' => 'integer',
            'rule' => 'array',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'commission_payout_id');
    }
}

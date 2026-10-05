<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Shared\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campanha de e-mail (campanhas.md): MARKETING, separada dos e-mails
 * transacionais. Situacao: draft (rascunho) -> sending (enviando aos poucos)
 * -> completed; ou cancelled. Campanhas do sistema antigo (is_legacy) sao so
 * o resumo (D-20).
 *
 * @property int $id
 * @property string|null $name
 * @property string $channel
 * @property string|null $template
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $segment
 * @property array<string, int|string|null>|null $segment_params
 * @property string $status
 * @property int $total_recipients
 * @property int $sent_count
 * @property int $failed_count
 * @property int $skipped_count
 * @property string|null $created_by_label
 * @property int|null $created_by_user_id
 * @property int|null $sent_by_user_id
 * @property string|null $request_key
 * @property bool $is_legacy
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 */
class Campaign extends Model
{
    use Auditable;

    public const STATUSES = ['draft' => 'Rascunho', 'sending' => 'Enviando', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'];

    protected $table = 'campaigns';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment_params' => 'array',
            'is_legacy' => 'boolean',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

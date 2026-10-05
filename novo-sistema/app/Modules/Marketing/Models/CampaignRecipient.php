<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinatario fotografado na hora de iniciar a campanha (um por cliente,
 * unico no banco). queued -> dispatched (entregue a fila central) | skipped
 * (sem consentimento ou descadastrado na hora do envio; campanha cancelada).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int|null $customer_id
 * @property string $email
 * @property string $status
 * @property string|null $skip_reason
 * @property int|null $email_message_id
 */
class CampaignRecipient extends Model
{
    protected $table = 'campaign_recipients';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}

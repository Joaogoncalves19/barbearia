<?php

namespace App\Modules\Receipts\Models;

use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de cada envio de comprovante por e-mail (so inclusao): o que,
 * para onde, quem pediu e quando. A chave do formulario impede o envio em
 * dobro (duplo clique).
 *
 * @property int $id
 * @property ReceiptType $receipt_type
 * @property int $receipt_id
 * @property string $email
 * @property int|null $requested_by_user_id
 * @property int|null $requested_by_customer_id
 * @property string $request_key
 * @property Carbon|null $created_at
 */
class ReceiptDelivery extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'receipt_deliveries';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['receipt_type' => ReceiptType::class];
    }
}

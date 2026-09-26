<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCard extends Model
{
    protected $table = 'gift_cards';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => GiftCardStatus::class,
            'issued_at' => 'datetime',
            'expires_on' => 'date',
            'redeemed_at' => 'datetime',
        ];
    }

    public function redeemedAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'redeemed_appointment_id');
    }
}

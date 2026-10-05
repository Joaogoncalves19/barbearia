<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\ReminderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentReminder extends Model
{
    protected $table = 'appointment_reminders';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'sent_at' => 'datetime',
            'scheduled_for' => 'immutable_datetime',
            'email_message_id' => 'integer',
            'notified_in_app' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}

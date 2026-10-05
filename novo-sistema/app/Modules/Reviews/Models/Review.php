<?php

namespace App\Modules\Reviews\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Avaliacao de um atendimento concluido (avaliacoes.md). Uma por atendimento
 * (unico no banco). Nota e comentario sao do cliente e NAO mudam depois de
 * enviados; a equipe modera (aprova, recusa, destaca), sempre auditado. O
 * comentario e TEXTO: escapado em toda tela (nunca HTML; regressao S-02).
 *
 * @property int $id
 * @property int|null $appointment_id
 * @property int|null $attendance_id
 * @property int|null $customer_id
 * @property int|null $professional_id
 * @property int $rating
 * @property string|null $comment
 * @property bool $is_featured
 * @property ReviewStatus $status
 * @property int|null $moderated_by_user_id
 * @property CarbonInterface|null $moderated_at
 * @property string|null $moderation_reason
 * @property int|null $featured_by_user_id
 * @property bool $is_legacy
 * @property CarbonInterface|null $reviewed_at
 */
class Review extends Model
{
    protected $table = 'reviews';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_featured' => 'boolean',
            'is_legacy' => 'boolean',
            'status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return HasMany<ReviewReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ReviewReply::class);
    }

    /**
     * @return HasOne<ReviewReply, $this>
     */
    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $r): void {
            if ($r->rating < 1 || $r->rating > 5) {
                throw DomainRuleViolation::rule('R-AVAL', 'Nota deve ser de 1 a 5.');
            }
            if ($r->is_featured && $r->status !== ReviewStatus::Approved) {
                throw DomainRuleViolation::rule('R-AVAL', 'Só avaliação publicada pode ser destacada.');
            }
        });
        static::updating(function (self $r): void {
            if ($r->isDirty(['rating', 'comment', 'customer_id', 'attendance_id', 'appointment_id', 'professional_id', 'reviewed_at'])) {
                throw DomainRuleViolation::rule('R-AVAL', 'Nota e comentário do cliente não mudam depois de enviados.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Avaliação é histórico: recuse em vez de apagar.'));
    }
}

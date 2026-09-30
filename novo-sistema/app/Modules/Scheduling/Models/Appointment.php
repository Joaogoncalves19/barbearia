<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\Payment;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\CancelledBy;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Team\Models\Professional;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Atendimento agendado. Contato do cliente e nome do profissional sao
 * fotografados na criacao. Mudanca de status so pelas transicoes do enum.
 *
 * Criar, remarcar, cancelar e mudar status: SO pelo BookingService (Fase 5).
 *
 * @property int $id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property AppointmentStatus $status
 * @property string|null $code
 * @property int|null $customer_id
 * @property int|null $professional_id
 * @property string|null $professional_name
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property AppointmentSource $source
 * @property string|null $notes
 * @property int|null $total_cents
 * @property Carbon|null $cancelled_at
 * @property CancelledBy|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property Carbon|null $confirmed_at
 * @property int $customer_reschedules
 * @property int|null $created_by_user_id
 */
#[UseFactory(AppointmentFactory::class)]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use Auditable, HasFactory;

    /** Sem 0/O e 1/I/L para leitura por telefone. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $table = 'appointments';

    /**
     * Contato do cliente fica fora da auditoria (dado pessoal desnecessario
     * na trilha; o agendamento ja guarda a fotografia).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['customer_email', 'customer_phone'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'cancelled_by' => CancelledBy::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'customer_reschedules' => 'integer',
            'cancelled_at' => 'datetime',
            'confirmation_requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a): void {
            $a->code ??= static::generateCode();
        });

        static::saving(function (self $a): void {
            if ($a->ends_at === null || $a->starts_at === null || $a->ends_at->lte($a->starts_at)) {
                throw DomainRuleViolation::rule('R-AGENDA', 'O agendamento deve terminar depois de comecar.');
            }
        });

        static::updating(function (self $a): void {
            if (! $a->isDirty('status')) {
                return;
            }
            $de = AppointmentStatus::from((string) $a->getRawOriginal('status'));
            if (! $de->canTransitionTo($a->status)) {
                throw DomainRuleViolation::rule('R-STATUS', "Transicao de status proibida: {$de->value} -> {$a->status->value}.");
            }
        });
    }

    public static function generateCode(): string
    {
        do {
            $code = 'AG-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
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
     * @return HasMany<AppointmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /**
     * @return HasMany<AppointmentAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(AppointmentAdjustment::class);
    }

    /**
     * @return HasMany<AppointmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class);
    }

    /**
     * @return HasMany<AppointmentReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}

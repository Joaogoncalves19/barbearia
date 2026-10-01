<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Checkout\Enums\AttendanceSource;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Support\PublicCode;
use App\Modules\Team\Models\Professional;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * O ATENDIMENTO: o que aconteceu quando o cliente chegou (atendimento.md).
 * Diferente do agendamento (a reserva): nasce dele ou de um encaixe.
 *
 * Fotografias: nome/telefone do cliente e nome do profissional no momento em
 * que o atendimento foi aberto (e do profissional escolhido, se trocado antes
 * de concluir); subtotal, desconto, total e gorjeta gravados na conclusao.
 * Referencias: customer_id, professional_id, appointment_id.
 *
 * Tudo que muda o atendimento passa pelo AttendanceService. Concluido ou
 * cancelado, o registro nao muda mais (a model recusa).
 *
 * @property int $id
 * @property string $code
 * @property AttendanceSource $source
 * @property int|null $appointment_id
 * @property int|null $active_appointment_id
 * @property int|null $customer_id
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property int|null $professional_id
 * @property string|null $professional_name
 * @property AttendanceStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property int|null $subtotal_cents
 * @property int|null $discount_cents
 * @property int|null $total_cents
 * @property int|null $tip_cents
 * @property string|null $notes
 * @property int|null $opened_by_user_id
 * @property int|null $completed_by_user_id
 * @property int|null $cancelled_by_user_id
 * @property string|null $completion_key
 * @property int $version
 */
#[UseFactory(AttendanceFactory::class)]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use Auditable, HasFactory;

    protected $table = 'attendances';

    protected $guarded = ['id'];

    /**
     * Telefone e dado pessoal desnecessario na trilha; version e a linha de
     * trava (muda a cada operacao, sem significado de negocio).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['customer_phone', 'version', 'completion_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'status' => AttendanceStatus::class,
            'opened_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'tip_cents' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a): void {
            $a->code ??= PublicCode::generate('AT', self::class);
        });

        static::updating(function (self $a): void {
            $de = AttendanceStatus::from((string) $a->getRawOriginal('status'));

            if ($de->isFinal()) {
                // Historico: concluido/cancelado nao muda (so a linha de trava).
                $alteradas = array_diff(array_keys($a->getDirty()), ['version', 'updated_at']);
                if ($alteradas !== []) {
                    throw DomainRuleViolation::rule('R-HIST', "Atendimento {$de->value} nao pode ser alterado. Colunas: ".implode(', ', $alteradas));
                }

                return;
            }

            if ($a->isDirty('status') && ! $de->canTransitionTo($a->status)) {
                throw DomainRuleViolation::rule('R-STATUS', "Transicao de status proibida: {$de->value} -> {$a->status->value}.");
            }
        });

        static::deleting(function (): void {
            throw DomainRuleViolation::rule('R-HIST', 'Atendimento nao e apagado; cancele-o.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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
     * @return HasMany<AttendanceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AttendanceItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<AttendanceConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(AttendanceConsumption::class)->orderBy('id');
    }

    /**
     * @return HasMany<AttendanceDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(AttendanceDiscount::class)->orderBy('id');
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}

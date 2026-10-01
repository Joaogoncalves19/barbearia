<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vale-presente (vale-presente.md): FORMA DE PAGAMENTO (decisao do dono). A
 * venda entra no caixa; o uso e um pagamento do atendimento apontando o vale.
 * Uso unico (R-13): usado de uma vez, ate o valor do vale.
 *
 * Estados: disponivel -> usado | cancelado. "Vencido" e calculado pela data
 * (expires_on), sem mudar o registro. Codigo, valor e venda nunca mudam.
 * Vale do sistema antigo (is_legacy): a venda nao foi registrada la.
 *
 * @property int $id
 * @property string $code
 * @property int $amount_cents
 * @property GiftCardStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_on
 * @property Carbon|null $redeemed_at
 * @property int|null $redeemed_appointment_id
 * @property int|null $redeemed_attendance_id
 * @property string|null $purchaser_name
 * @property string|null $purchaser_email
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property string|null $message
 * @property PaymentMethod|null $sale_method
 * @property int|null $sale_cash_session_id
 * @property int|null $sold_by_user_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by_user_id
 * @property bool $is_legacy
 * @property string|null $request_key
 * @property int $version
 */
class GiftCard extends Model
{
    protected $table = 'gift_cards';

    protected $guarded = ['id'];

    private const MUDAM = ['status', 'redeemed_at', 'redeemed_appointment_id', 'redeemed_attendance_id', 'cancelled_at', 'cancel_reason', 'cancelled_by_user_id', 'version', 'updated_at'];

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
            'sale_method' => PaymentMethod::class,
            'cancelled_at' => 'datetime',
            'is_legacy' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $g): void {
            if ($g->amount_cents < 1 || $g->amount_cents > 100_000_00) {
                throw DomainRuleViolation::rule('R-VALE-PRESENTE', 'Vale-presente: valor entre R$ 0,01 e R$ 100.000,00.');
            }
        });
        static::updating(function (self $g): void {
            if (array_diff(array_keys($g->getDirty()), self::MUDAM) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Vale-presente: codigo, valor e venda nunca mudam.');
            }
            $antes = $g->getOriginal('status');
            $antes = $antes instanceof GiftCardStatus ? $antes : GiftCardStatus::tryFrom((string) $antes);
            if ($g->isDirty('status') && $antes !== GiftCardStatus::Available) {
                throw DomainRuleViolation::rule('R-VALE-PRESENTE', 'Vale-presente usado ou cancelado nao muda mais.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Vale-presente e historico.'));
    }

    /** Nas URLs do painel o vale aparece pelo codigo. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < BusinessTime::today();
    }

    /** Pode pagar agora: disponivel e dentro da validade. */
    public function isUsable(): bool
    {
        return $this->status === GiftCardStatus::Available && ! $this->isExpired();
    }

    /** Situacao para as telas (vencido e calculado). */
    public function situationLabel(): string
    {
        return $this->status === GiftCardStatus::Available && $this->isExpired() ? 'Vencido' : $this->status->label();
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function redeemedAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'redeemed_appointment_id');
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function redeemedAttendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'redeemed_attendance_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }
}

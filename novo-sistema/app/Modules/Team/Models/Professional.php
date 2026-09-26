<?php

namespace App\Modules\Team\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Enums\SubscriptionCommissionMode;
use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quem atende. Pode ter login (user_id) ou nao. Nunca e apagado de fato
 * enquanto houver historico (FK restrict em agendamentos, comissoes e vales).
 *
 * @property int|null $commission_rate_bp
 * @property int|null $subscription_commission_rate_bp
 * @property SubscriptionCommissionMode|null $subscription_commission_mode
 */
#[UseFactory(ProfessionalFactory::class)]
class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'professionals';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'commission_rate_bp' => 'integer',
            'commission_on_products' => 'boolean',
            'subscription_commission_mode' => SubscriptionCommissionMode::class,
            'subscription_commission_rate_bp' => 'integer',
            'subscription_commission_amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p): void {
            foreach (['commission_rate_bp', 'subscription_commission_rate_bp'] as $campo) {
                if ($p->{$campo} !== null && ($p->{$campo} < 0 || $p->{$campo} > 10000)) {
                    throw DomainRuleViolation::rule('R-COMISSAO', 'Percentual de comissao deve ficar entre 0% e 100%.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'professional_service');
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'professional_package');
    }

    /**
     * @return HasMany<WorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    /**
     * @return HasMany<ScheduleBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(ScheduleBreak::class);
    }

    /**
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /**
     * @return HasMany<BlockedSlot, $this>
     */
    public function blockedSlots(): HasMany
    {
        return $this->hasMany(BlockedSlot::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}

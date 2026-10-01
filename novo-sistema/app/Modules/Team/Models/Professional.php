<?php

namespace App\Modules\Team\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Support\UniqueSlug;
use App\Modules\Team\Enums\SubscriptionCommissionMode;
use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quem atende: a UNICA definicao de profissional. Conceito separado de
 * User (conta de acesso) e de Customer:
 *
 *   User (login, papel) 0..1 ──── 0..1 Professional (atendimento, agenda)
 *
 * Um profissional pode existir SEM login (user_id nulo); quem precisa entrar
 * no painel tem a conta vinculada. Nunca e apagado de fato: desligamento =
 * inativo (FK restrict em agendamentos, comissoes e vales preservam o
 * historico; o agendamento guarda ainda o nome fotografado).
 *
 * Estados (booleanos independentes, combinados SO pelos escopos abaixo):
 * - is_active: faz parte da equipe. Inativo = desligado (historico intacto).
 * - is_bookable: recebe agendamentos novos (ativo, mas ex.: so atende na
 *   recepcao ou esta em treinamento).
 * - is_public / is_featured: aparece / tem destaque no site (Fase 11).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $display_name
 * @property string|null $slug
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $photo_path
 * @property bool $is_active
 * @property bool $is_bookable
 * @property bool $is_public
 * @property bool $is_featured
 * @property int $sort_order
 * @property int $lock_version
 * @property int $ledger_version
 * @property int|null $subscription_commission_rate_bp
 * @property SubscriptionCommissionMode|null $subscription_commission_mode
 */
#[UseFactory(ProfessionalFactory::class)]
class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'professionals';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['lock_version', 'ledger_version'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
            'ledger_version' => 'integer',
            'subscription_commission_mode' => SubscriptionCommissionMode::class,
            'subscription_commission_rate_bp' => 'integer',
            'subscription_commission_amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p): void {
            $p->slug ??= UniqueSlug::for('professionals', $p->display_name);
        });

        static::saving(function (self $p): void {
            foreach (['subscription_commission_rate_bp'] as $campo) {
                if ($p->{$campo} !== null && ($p->{$campo} < 0 || $p->{$campo} > 10000)) {
                    throw DomainRuleViolation::rule('R-COMISSAO', 'Percentual de comissao deve ficar entre 0% e 100%.');
                }
            }
        });
    }

    /**
     * Recebe agendamentos novos: ativo e agendavel.
     *
     * @param  Builder<self>  $q
     */
    public function scopeBookable(Builder $q): void
    {
        $q->where('professionals.is_active', true)->where('professionals.is_bookable', true);
    }

    /**
     * Aparece no site: ativo e publico.
     *
     * @param  Builder<self>  $q
     */
    public function scopeShownPublicly(Builder $q): void
    {
        $q->where('professionals.is_active', true)->where('professionals.is_public', true);
    }

    /**
     * @param  Builder<self>  $q
     */
    public function scopeOrdered(Builder $q): void
    {
        $q->orderBy('professionals.sort_order')->orderBy('professionals.display_name');
    }

    public function isBookable(): bool
    {
        return $this->is_active && $this->is_bookable;
    }

    public function photoUrl(): ?string
    {
        return ImageStore::url($this->photo_path);
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

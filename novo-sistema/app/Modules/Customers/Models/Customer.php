<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Email;
use App\Modules\Customers\Support\Phone;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Team\Models\Professional;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Cliente final. Autentica pelo guard "customer" (tabela propria, separada
 * da equipe).
 *
 * Normalizacao na gravacao: e-mail minusculo, telefone E.164, CPF so
 * digitos. Valor invalido vira null (nunca um valor "quase certo").
 *
 * status, consentimento e vinculos de mesclagem ficam fora do $fillable:
 * mudam por acao explicita (servicos de dominio), nao por formulario.
 */
#[Fillable(['name', 'email', 'phone', 'cpf', 'password', 'birth_date'])]
#[Hidden(['password', 'remember_token', 'cpf'])]
#[UseFactory(CustomerFactory::class)]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'customers';

    /** @var list<string> */
    protected array $auditExclude = ['password', 'remember_token'];

    /** @var list<string> */
    protected array $auditMask = ['cpf'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'status' => CustomerStatus::class,
            'marketing_email_consent' => MarketingConsent::class,
            'marketing_consent_updated_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            $c->public_id ??= (string) Str::ulid();
            $c->status ??= CustomerStatus::Active;
            $c->marketing_email_consent ??= MarketingConsent::Unknown;
        });
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = Email::normalize($value);
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Phone::normalize($value);
    }

    public function setCpfAttribute(?string $value): void
    {
        $this->attributes['cpf'] = Cpf::normalize($value);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_customer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_customer_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function loyaltyEntries(): HasMany
    {
        return $this->hasMany(LoyaltyEntry::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function favoriteProfessionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'customer_favorite_professionals')->withPivot('created_at');
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /**
     * Pode receber e-mail de marketing? So com consentimento explicito E sem
     * supressao do e-mail. "Desconhecido" NAO autoriza.
     */
    public function canReceiveMarketingEmail(): bool
    {
        return $this->email !== null
            && $this->marketing_email_consent === MarketingConsent::Granted
            && ! EmailSuppression::isSuppressed($this->email);
    }
}

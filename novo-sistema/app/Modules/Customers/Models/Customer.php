<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Email;
use App\Modules\Customers\Support\Phone;
use App\Modules\Identity\Notifications\CustomerResetPassword;
use App\Modules\Identity\Notifications\CustomerVerifyEmail;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Team\Models\Professional;
use Database\Factories\CustomerFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cliente final. Autentica pelo guard "customer" (tabela propria, separada
 * da equipe), com senha ou link magico (Fase 3).
 *
 * CPF e OBRIGATORIO para o cliente (decisao da Fase 3). A coluna continua
 * anulavel so porque registros vindos do importador (ou, no futuro, de um
 * cadastro incompleto) podem chegar sem ele: esses clientes precisam
 * informar o CPF antes de usar a conta (middleware customer.complete).
 *
 * Normalizacao na gravacao: e-mail minusculo, telefone E.164, CPF so
 * digitos. Valor invalido vira null (nunca um valor "quase certo").
 *
 * status, consentimento e vinculos de mesclagem ficam fora do $fillable:
 * mudam por acao explicita (servicos de dominio), nao por formulario.
 *
 * @property ?string $email
 * @property CustomerStatus $status
 * @property MarketingConsent $marketing_email_consent
 * @property Carbon|null $birth_date
 * @property ?string $cpf
 * @property ?string $phone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $anonymized_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property int|null $merged_into_customer_id
 * @property string|null $referral_code
 * @property int|null $referred_by_customer_id
 */
#[Fillable(['name', 'email', 'phone', 'cpf', 'password', 'birth_date'])]
#[Hidden(['password', 'remember_token', 'cpf'])]
#[UseFactory(CustomerFactory::class)]
class Customer extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'customers';

    /** @var list<string> */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at'];

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
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            // CPF obrigatorio para QUALQUER cliente novo (site, balcao, qualquer
            // canal futuro). So o importador (query builder) grava sem CPF:
            // excecao legada, tratada no primeiro acesso (customer.complete).
            if ($c->cpf === null) {
                throw DomainRuleViolation::rule('R-CPF', 'Cliente novo precisa de CPF valido.');
            }
            $c->public_id ??= (string) Str::ulid();
            $c->referral_code ??= self::newReferralCode(); // Fase 8: codigo de indicacao
            $c->status ??= CustomerStatus::Active;
            $c->marketing_email_consent ??= MarketingConsent::Unknown;
        });

        // Quem tem CPF nunca volta a ficar sem (so pode ser corrigido).
        static::updating(function (self $c): void {
            if ($c->isDirty('cpf') && $c->getAttribute('cpf') === null && $c->getRawOriginal('cpf') !== null) {
                throw DomainRuleViolation::rule('R-CPF', 'O CPF do cliente nao pode ser removido.');
            }
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

    /**
     * Pode entrar e usar a conta? Ativo, nao mesclado em outro cadastro, nao
     * anonimizado e nao excluido.
     */
    public function canSignIn(): bool
    {
        return $this->status === CustomerStatus::Active
            && $this->merged_into_customer_id === null
            && $this->anonymized_at === null
            && ! $this->trashed();
    }

    /**
     * Tem senha? Cliente cadastrado no balcao, importado com hash nao
     * reconhecido ou que so usa link magico nao tem.
     */
    public function hasPassword(): bool
    {
        return $this->getAttribute('password') !== null;
    }

    /** Falta dado obrigatorio da conta (hoje: o CPF)? */
    public function needsProfileCompletion(): bool
    {
        return $this->cpf === null;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->email !== null) {
            $this->notify(new CustomerResetPassword($token));
        }
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->email !== null) {
            $this->notify(new CustomerVerifyEmail);
        }
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_customer_id');
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_customer_id');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<LoyaltyEntry, $this>
     */
    public function loyaltyEntries(): HasMany
    {
        return $this->hasMany(LoyaltyEntry::class);
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * @return BelongsToMany<Professional, $this>
     */
    public function favoriteProfessionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'customer_favorite_professionals')->withPivot('created_at');
    }

    /**
     * @return HasMany<ConsentRecord, $this>
     */
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

    /** Codigo de indicacao unico (Fase 8), facil de ditar: 8 letras/numeros sem ambiguidade. */
    public static function newReferralCode(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = '';
            for ($i = 0; $i < 8; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
        } while (static::query()->where('referral_code', $codigo)->exists());

        return $codigo;
    }
}

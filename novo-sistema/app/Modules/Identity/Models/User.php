<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Authorization\PermissionMatrix;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Notifications\StaffResetPassword;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Team\Models\Professional;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Usuario da EQUIPE (proprietario, gerente, recepcao, financeiro, profissional).
 *
 * Entra com o nome de usuario OU com o e-mail (decisao da Fase 3). O nome de
 * usuario e o identificador da conta; o e-mail e opcional e serve tambem para
 * recuperar a senha e receber avisos.
 *
 * `role`, `is_active` e `must_change_password` ficam fora do $fillable de
 * proposito: sao alterados so pelos servicos de Identity (StaffAccounts,
 * PasswordManager), nunca por mass assignment de formulario.
 *
 * @property StaffRole|null $role
 * @property bool|null $is_active
 * @property bool|null $must_change_password
 * @property string|null $email
 * @property string|null $username
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 */
#[Fillable(['name', 'email', 'username', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /** @var list<string> */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at'];

    /**
     * Padroes em memoria iguais aos do banco (model recem-criado ja os tem).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'must_change_password' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'role' => StaffRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $u): void {
            $u->email = self::normalizeEmail($u->email);
            $u->username = self::normalizeUsername($u->username);

            // Sem identificador so enquanto inativo (ex.: conta importada com
            // usuario repetido, aguardando decisao): nunca entra no painel.
            if ($u->is_active !== false && $u->email === null && $u->username === null) {
                throw DomainRuleViolation::rule('R-EQUIPE', 'Usuario ativo da equipe precisa de e-mail ou nome de usuario.');
            }
        });
    }

    public static function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : (mb_strtolower(trim($email)) ?: null);
    }

    public static function normalizeUsername(?string $username): ?string
    {
        return $username === null ? null : (mb_strtolower(trim($username)) ?: null);
    }

    /**
     * @return HasOne<Professional, $this>
     */
    public function professional(): HasOne
    {
        return $this->hasOne(Professional::class);
    }

    /**
     * Permissao efetiva pela matriz (deny by default). As Gates chamam isto;
     * no codigo use sempre Gate/can(), nunca este metodo direto.
     */
    public function hasPermission(string $ability): bool
    {
        return PermissionMatrix::staffAllows($this, $ability);
    }

    /** Pode entrar agora? (a senha e conferida a parte) */
    public function canSignIn(): bool
    {
        return (bool) $this->is_active && $this->role !== null;
    }

    public function isOwner(): bool
    {
        return $this->role === StaffRole::Owner;
    }

    /** Identificador exibido (nunca a senha, nunca o e-mail completo em log). */
    public function loginLabel(): string
    {
        return $this->username ?? (string) $this->email;
    }

    /**
     * Redefinicao por e-mail: so para quem tem e-mail (quem nao tem pede ao
     * proprietario uma senha provisoria).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->email !== null) {
            $this->notify(new StaffResetPassword($token));
        }
    }
}

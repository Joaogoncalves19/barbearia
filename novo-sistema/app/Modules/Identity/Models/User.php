<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Models\Professional;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario da EQUIPE (proprietario, gerente, recepcao, financeiro, profissional).
 *
 * `role` e `is_active` ficam fora do $fillable de proposito: mudar papel ou
 * desativar alguem e uma acao administrativa explicita (Fase 3), nunca efeito
 * colateral de um formulario com mass assignment.
 *
 * @property StaffRole|null $role
 * @property bool|null $is_active
 * @property string|null $email
 * @property string|null $username
 */
#[Fillable(['name', 'email', 'username', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => StaffRole::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $u): void {
            $u->email = $u->email === null ? null : (mb_strtolower(trim($u->email)) ?: null);
            $u->username = $u->username === null ? null : (mb_strtolower(trim($u->username)) ?: null);

            // Sem identificador so enquanto inativo (ex.: conta importada com
            // usuario repetido, aguardando decisao): nunca entra no painel.
            if ($u->is_active !== false && $u->email === null && $u->username === null) {
                throw DomainRuleViolation::rule('R-EQUIPE', 'Usuario ativo da equipe precisa de e-mail ou nome de usuario.');
            }
        });
    }

    /**
     * @return HasOne<Professional, $this>
     */
    public function professional(): HasOne
    {
        return $this->hasOne(Professional::class);
    }

    /**
     * Permissao efetiva. Nega por padrao: usuario inativo, papel sem
     * mapeamento ou habilidade nao listada => false.
     */
    public function hasPermission(string $ability): bool
    {
        if (! $this->is_active || ! $this->role instanceof StaffRole) {
            return false;
        }

        $granted = (array) config('permissions.roles.'.$this->role->value, []);

        if (in_array('*', $granted, true)) {
            // Curinga vale apenas para habilidades DECLARADAS na matriz.
            return array_key_exists($ability, config('permissions.abilities', []));
        }

        return in_array($ability, $granted, true);
    }
}

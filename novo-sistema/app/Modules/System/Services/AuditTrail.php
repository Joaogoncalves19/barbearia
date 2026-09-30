<?php

namespace App\Modules\System\Services;

use App\Modules\System\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Eventos de SEGURANCA de conta na auditoria (login, logout, troca e
 * redefinicao de senha, papel alterado...). Alteracoes de dados de um model
 * ja sao registradas pela trait Auditable.
 *
 * Nunca recebe senha nem token: o contexto aceito e so o que for passado
 * explicitamente, e as chaves com cara de segredo sao descartadas.
 *
 * Tentativas de login RECUSADAS nao vem para ca (um ataque encheria o banco):
 * vao para o log do canal "security", com o identificador em hash.
 */
final class AuditTrail
{
    private const SECRET_KEYS = ['password', 'password_confirmation', 'current_password', 'token', 'remember_token'];

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Authenticatable $actor = null,
        ?string $description = null,
        array $context = [],
    ): void {
        $actor ??= Auth::user();

        AuditLog::create([
            'actor_type' => $actor ? class_basename($actor) : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_label' => $actor instanceof Model ? $actor->getAttribute('name') : null,
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'new_values' => array_diff_key($context, array_flip(self::SECRET_KEYS)) ?: null,
            'description' => $description,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
        ]);
    }
}

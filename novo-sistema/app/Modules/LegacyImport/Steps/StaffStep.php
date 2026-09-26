<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;

/**
 * users (administradores antigos) -> users (equipe).
 *
 * - Login continua por usuario (D-10 pendente); username em minusculas e
 *   unico. Colisao (inclusive entre maiusculas/minusculas) = pendencia, e o
 *   segundo fica sem username (sem acesso) ate a decisao.
 * - Hash bcrypt antigo mantido como esta (rehash no primeiro login).
 *   Formato nao reconhecido => sem senha (redefinicao obrigatoria).
 * - Perfil desconhecido => recepcao INATIVO (menor privilegio) + pendencia.
 */
final class StaffStep extends Step
{
    public const ROLE_MAP = [
        'proprietario' => StaffRole::Owner,
        'gerente' => StaffRole::Manager,
        'recepcao' => StaffRole::Reception,
        'financeiro' => StaffRole::Finance,
    ];

    public function name(): string
    {
        return 'Equipe (administradores)';
    }

    public function tables(): array
    {
        return ['users'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('users') as $row) {
            $sid = (string) $row['username'];
            if ($this->ctx->status('users', $sid, $row) !== 'new') {
                continue;
            }

            $username = $this->uniqueUsername('users', $sid, $sid);
            $roleRaw = V::text($row['role'] ?? null) ?? 'proprietario'; // default da coluna no sistema antigo
            $role = self::ROLE_MAP[$roleRaw] ?? null;
            if ($role === null) {
                $this->ctx->issue('users', $sid, C::Unknown, S::Warning, 'unknown_role',
                    "Perfil \"{$roleRaw}\" desconhecido: importado como recepcao INATIVO.", ['role' => $roleRaw], true);
            }

            $id = $this->ctx->insert('users', [
                'name' => V::text($row['username']) ?? '(sem nome)',
                'username' => $username,
                'email' => null,
                'password' => $this->password('users', $sid, $row['password_hash'] ?? null),
                'role' => ($role ?? StaffRole::Reception)->value,
                'is_active' => $role !== null && $username !== null,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('users', $sid, 'user', $id, $row);
        }
    }
}

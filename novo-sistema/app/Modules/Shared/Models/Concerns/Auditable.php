<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Customers\Support\Cpf;
use App\Modules\System\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Registra em audit_logs quem criou, alterou ou apagou o registro e o que
 * mudou. Campos em $auditExclude nunca sao gravados (senha, token); campos
 * em $auditMask sao mascarados (CPF).
 *
 * O importador grava pelo query builder e nao passa por aqui: a trilha da
 * migracao e o proprio import_runs/legacy_references.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => static::writeAudit($m, 'created', [], $m->getAttributes()));
        static::updated(function (Model $m): void {
            $novos = $m->getChanges();
            unset($novos['updated_at']);
            if ($novos !== []) {
                static::writeAudit($m, 'updated', array_intersect_key($m->getRawOriginal(), $novos), $novos);
            }
        });
        static::deleted(fn (Model $m) => static::writeAudit($m, 'deleted', $m->getAttributes(), []));
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     */
    protected static function writeAudit(Model $m, string $acao, array $antes, array $depois): void
    {
        $ator = Auth::user();

        AuditLog::create([
            'actor_type' => $ator ? class_basename($ator) : null,
            'actor_id' => $ator?->getAuthIdentifier(),
            'actor_label' => $ator->name ?? null,
            'action' => $acao,
            'auditable_type' => class_basename($m),
            'auditable_id' => $m->getKey(),
            'old_values' => static::sanitizeAudit($m, $antes) ?: null,
            'new_values' => static::sanitizeAudit($m, $depois) ?: null,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    protected static function sanitizeAudit(Model $m, array $valores): array
    {
        $excluir = array_merge(['password', 'remember_token'], $m->auditExclude ?? []);
        $valores = array_diff_key($valores, array_flip($excluir));

        foreach ($m->auditMask ?? [] as $campo) {
            if (! empty($valores[$campo])) {
                $valores[$campo] = $campo === 'cpf' ? Cpf::mask((string) $valores[$campo]) : '***';
            }
        }

        return $valores;
    }
}

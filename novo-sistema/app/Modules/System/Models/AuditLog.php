<?php

namespace App\Modules\System\Models;

use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $description
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    use AppendOnly;

    protected $table = 'audit_logs';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Resumo legivel do que mudou ("price_cents: 4500 → 5000"), para a tela
     * de auditoria. Os valores ja foram limpos na gravacao (sem senha/token,
     * CPF mascarado).
     */
    public function changesSummary(int $limit = 4): string
    {
        $novos = $this->new_values ?? [];
        $antigos = $this->old_values ?? [];
        $partes = [];

        foreach (array_slice($novos, 0, $limit, true) as $campo => $valor) {
            $partes[] = array_key_exists($campo, $antigos)
                ? $campo.': '.self::show($antigos[$campo]).' → '.self::show($valor)
                : $campo.': '.self::show($valor);
        }

        return implode(' · ', $partes).(count($novos) > $limit ? ' …' : '');
    }

    private static function show(mixed $valor): string
    {
        return match (true) {
            $valor === null => '—',
            is_bool($valor) => $valor ? 'sim' : 'não',
            is_scalar($valor) => mb_strimwidth((string) $valor, 0, 60, '…'),
            default => mb_strimwidth((string) json_encode($valor, JSON_UNESCAPED_UNICODE), 0, 60, '…'),
        };
    }
}

<?php

namespace App\Http\Requests\Panel\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Regras de entrada comuns ao catalogo e a equipe.
 */
trait CatalogRules
{
    /**
     * Nome unico (sem diferenciar maiusculas) entre os registros NAO excluidos.
     */
    protected function uniqueName(string $table, string $column, ?int $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $column, $ignoreId, $message): void {
            $existe = DB::table($table)
                ->whereNull('deleted_at')
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim((string) $value))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($existe) {
                $fail($message);
            }
        };
    }

    /**
     * Um campo so pode mudar com a habilidade. Campo ausente = sem mudanca
     * (o formulario nem mostra o campo para quem nao pode). Campo presente,
     * diferente do atual e sem a habilidade = pedido adulterado: 403.
     */
    protected function changesWithoutAbility(string $field, mixed $current, string $ability): bool
    {
        if (! $this->has($field)) {
            return false;
        }

        $novo = $this->input($field);
        $mudou = is_bool($current)
            ? $this->boolean($field) !== $current
            : (string) $novo !== (string) $current;

        return $mudou && ! (bool) $this->user('web')?->can($ability);
    }
}

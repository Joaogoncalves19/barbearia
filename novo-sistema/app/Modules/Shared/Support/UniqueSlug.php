<?php

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Identificador estavel e legivel ("corte-degrade") para categorias,
 * servicos e profissionais. Gerado UMA vez, na criacao: mudar o nome nao
 * muda o slug (links do site continuam valendo). Colisao ganha sufixo -2, -3.
 *
 * Usado pelos models (creating) e pelo importador (que grava pelo query
 * builder): a regra e uma so.
 */
final class UniqueSlug
{
    public static function for(string $table, string $name): string
    {
        $base = Str::limit(Str::slug($name), 70, '') ?: 'item';
        $slug = $base;

        // Inclui registros excluidos (soft delete): o slug nunca e reaproveitado.
        for ($i = 2; DB::table($table)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}

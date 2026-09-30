<?php

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ordem de exibicao controlada pelo sistema (sort_order). Mover um item
 * troca de lugar com o vizinho e renumera o grupo inteiro (10, 20, 30...),
 * numa transacao com bloqueio: duas pessoas movendo ao mesmo tempo nunca
 * deixam posicoes repetidas.
 */
final class Ordering
{
    public const UP = 'up';

    public const DOWN = 'down';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $group  os itens que disputam a ordem (ex.: servicos da mesma categoria)
     */
    public static function move(Builder $group, Model $item, string $direction): void
    {
        DB::transaction(function () use ($group, $item, $direction): void {
            $ids = (clone $group)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $pos = array_search($item->getKey(), $ids, true);

            if ($pos === false) {
                return;
            }

            $alvo = $direction === self::UP ? $pos - 1 : $pos + 1;
            if ($alvo >= 0 && $alvo < count($ids)) {
                [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
            }

            self::renumber($group->getModel()->getTable(), $ids);
        });
    }

    /**
     * Posicao para um item novo: fim do grupo.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $group
     */
    public static function next(Builder $group): int
    {
        return ((int) (clone $group)->max('sort_order')) + 10;
    }

    /**
     * @param  list<mixed>  $ids
     */
    private static function renumber(string $table, array $ids): void
    {
        foreach ($ids as $i => $id) {
            // Direto na tabela: reordenar nao e "alteracao do cadastro" (nao
            // mexe em updated_at/lock_version de quem esta editando o item).
            DB::table($table)->where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
        }
    }
}

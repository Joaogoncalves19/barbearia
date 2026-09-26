<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Shared\Exceptions\DomainRuleViolation;

/**
 * Registro historico: so inclusao. Corrige-se com um novo lancamento
 * (estorno/ajuste), nunca editando ou apagando o que ja aconteceu.
 *
 * O model pode liberar colunas de controle que mudam depois (ex.: vinculo
 * do lancamento de comissao ao pagamento da comissao) em $appendOnlyMutable.
 *
 * Observacao: isto protege o caminho Eloquent. Consultas diretas ao banco
 * (query builder) sao usadas apenas pelo importador, que so insere.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            $permitidas = array_merge($model->appendOnlyMutable ?? [], ['updated_at']);
            $alteradas = array_diff(array_keys($model->getDirty()), $permitidas);

            if ($alteradas !== []) {
                throw DomainRuleViolation::rule('R-HIST', class_basename($model).' e historico imutavel; registre um estorno/ajuste. Colunas: '.implode(', ', $alteradas));
            }
        });

        static::deleting(function ($model): void {
            throw DomainRuleViolation::rule('R-HIST', class_basename($model).' e historico imutavel e nao pode ser apagado.');
        });
    }
}

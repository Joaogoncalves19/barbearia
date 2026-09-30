<?php

namespace App\Modules\Shared\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * O registro foi alterado por outra pessoa depois que o formulario foi
 * aberto (concorrencia otimista por lock_version). Nada foi gravado.
 */
final class StaleRecord extends RuntimeException
{
    public static function for(Model $model): self
    {
        return new self(class_basename($model).' #'.$model->getKey().' foi alterado por outra pessoa.');
    }

    /**
     * Relê o registro com bloqueio e confere a versao que o formulario viu.
     * Chamar DENTRO de uma transacao.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public static function guard(Model $model, int $expectedVersion): Model
    {
        /** @var TModel $atual */
        $atual = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

        if ((int) $atual->getAttribute('lock_version') !== $expectedVersion) {
            throw self::for($model);
        }

        $atual->setAttribute('lock_version', $expectedVersion + 1);

        return $atual;
    }
}

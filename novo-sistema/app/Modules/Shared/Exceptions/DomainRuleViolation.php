<?php

namespace App\Modules\Shared\Exceptions;

use DomainException;

/** Uma regra de integridade do dominio foi violada (ver regras-dados.md). */
class DomainRuleViolation extends DomainException
{
    public static function rule(string $codigo, string $mensagem): self
    {
        return new self("[{$codigo}] {$mensagem}");
    }
}

<?php

namespace App\Modules\Subscriptions\Exceptions;

use RuntimeException;

/** Webhook recusado antes de qualquer gravacao (assinatura, janela ou corpo). */
final class InvalidWebhook extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Webhook recusado: '.$reason);
    }
}

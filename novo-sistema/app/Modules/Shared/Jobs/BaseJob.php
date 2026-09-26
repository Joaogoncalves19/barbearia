<?php

namespace App\Modules\Shared\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base para jobs de negocio (lembretes, campanhas, e-mails, webhooks...).
 *
 * Padroniza o que todo job precisa e que o sistema antigo nao tinha:
 * - retentativas limitadas com espera crescente (1, 5 e 15 minutos);
 * - registro da falha definitiva no canal de log 'jobs', sem dados sensiveis;
 * - timeout explicito, para um job travado nao segurar o worker.
 *
 * Jobs concretos devem ser IDEMPOTENTES: se rodarem duas vezes (retentativa),
 * o efeito precisa ser o mesmo (ex.: registrar "lembrete enviado" com chave
 * unica antes de mandar de novo).
 */
abstract class BaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return array<int, int> segundos entre as tentativas
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('jobs')->error('Job falhou definitivamente', [
            'job' => static::class,
            'erro' => $exception ? get_class($exception).': '.$exception->getMessage() : null,
        ]);
    }
}

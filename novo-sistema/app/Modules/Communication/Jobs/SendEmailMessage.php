<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Services\Outbox;
use App\Modules\Shared\Jobs\BaseJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entrega UM e-mail do registro central (fila.md). Retentativas: 4, com
 * espera crescente (1, 5, 15 e 60 min). Idempotente: o Outbox so entrega um
 * registro ainda "na fila" (o mesmo job rodando duas vezes nao reenvia).
 * Falha definitiva: o registro vira "falhou", com o erro, e pode ser
 * reenviado (app:communication retry).
 */
class SendEmailMessage extends BaseJob
{
    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('emails');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(Outbox $outbox): void
    {
        $outbox->deliver($this->messageId);
    }

    public function failed(?Throwable $exception): void
    {
        app(Outbox::class)->markFailed($this->messageId, $exception);
        // Sem a mensagem crua do provedor (pode trazer dados da conexao).
        Log::channel('jobs')->error('E-mail falhou definitivamente', [
            'registro' => $this->messageId,
            'erro' => $exception !== null ? Outbox::safeError($exception) : null,
        ]);
    }
}

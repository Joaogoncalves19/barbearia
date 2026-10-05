<?php

namespace App\Modules\Communication\Services;

use App\Modules\Customers\Models\CustomerNotification;
use Illuminate\Database\QueryException;

/**
 * Avisos na conta do cliente ("notificacoes no app"). Um aviso por chave de
 * unicidade: o mesmo aviso nunca aparece duas vezes (scheduler rodando de
 * novo, job repetido). Texto simples, escapado na tela.
 */
final class InAppNotifier
{
    public function notify(int $customerId, string $kind, string $message, ?string $link, ?string $dedupeKey): ?CustomerNotification
    {
        if ($dedupeKey !== null && ($existente = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->first()) !== null) {
            return $existente;
        }
        try {
            return CustomerNotification::query()->create([
                'customer_id' => $customerId,
                'kind' => mb_substr($kind, 0, 32),
                'message' => mb_substr($message, 0, 500),
                'link' => $link,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (QueryException $e) {
            if ($dedupeKey !== null && ($existente = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->first()) !== null) {
                return $existente;
            }
            throw $e;
        }
    }
}

<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\System\Services\AuditTrail;

/**
 * Retencao dos eventos do Stripe (decisao do dono P9-10, assinaturas.md §11):
 * depois de 12 meses do recebimento, o CORPO do evento (que traz dados
 * pessoais) e removido. Fica o necessario para auditoria tecnica/financeira:
 * gateway, ID do evento (o reenvio do mesmo evento continua reconhecido e nao
 * e reprocessado), tipo, situacao, resultado, erro tecnico, datas, tentativas
 * e a assinatura local. Idempotente; cada execucao que anonimiza vai para a
 * auditoria.
 */
final class GatewayEventRetention
{
    public const MONTHS = 12;

    public function run(): int
    {
        $limite = BusinessTime::now()->subMonths(self::MONTHS);
        $n = GatewayEvent::query()->whereNotNull('payload')
            ->where(fn ($q) => $q->where('received_at', '<', $limite)->orWhere(fn ($x) => $x->whereNull('received_at')->where('processed_at', '<', $limite)))
            ->update(['payload' => null, 'payload_purged_at' => BusinessTime::now()]);
        if ($n > 0) {
            AuditTrail::record('retention.gateway_events', null, null, $n.' evento(s) do Stripe anonimizado(s) (mais de '.self::MONTHS.' meses).', [
                'quantidade' => $n, 'recebidos_antes_de' => $limite->toIso8601String(),
            ]);
        }

        return $n;
    }
}

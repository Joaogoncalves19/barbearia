<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Exceptions\InvalidWebhook;
use App\Modules\Subscriptions\Services\StripeWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recebe o webhook do Stripe (webhooks.md). Respostas:
 * - 200: evento aceito (processado agora, ou ja processado antes: reentrega);
 * - 400: assinatura invalida, fora da janela (replay) ou corpo invalido —
 *   nada gravado;
 * - 503: segredo do webhook nao configurado — nada gravado;
 * - 500: erro ao processar — nada aplicado, evento marcado "failed"; o
 *   Stripe reenvia.
 * O corpo da resposta nunca traz dados do cliente nem detalhes do erro.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhook $webhook): JsonResponse
    {
        try {
            $r = $webhook->receive($request->getContent(), (string) $request->header('Stripe-Signature', ''));
        } catch (InvalidWebhook $e) {
            Log::warning('Webhook do Stripe recusado', ['motivo' => $e->reason, 'ip' => $request->ip()]);

            return response()->json(['error' => 'invalid'], $e->reason === 'not_configured' ? 503 : 400);
        } catch (Throwable) {
            return response()->json(['error' => 'retry'], 500);
        }

        return response()->json(['received' => true, 'result' => $r['result']]);
    }
}

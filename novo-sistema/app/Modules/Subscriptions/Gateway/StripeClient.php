<?php

namespace App\Modules\Subscriptions\Gateway;

use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador do Stripe (stripe.md): as POUCAS chamadas que o sistema faz a
 * API, por HTTP, com a versao da API fixada e chave de idempotencia em toda
 * escrita (repetir a mesma chamada nunca cobra, cancela ou reembolsa duas
 * vezes no Stripe). A chave secreta vem SO da configuracao (variavel de
 * ambiente) e nunca vai para o log, o banco ou o frontend.
 *
 * O Stripe e um sistema externo de pagamento, nao a fonte unica de verdade:
 * o resultado de cada chamada so muda o banco local pelos servicos de
 * assinatura, que guardam o proprio historico. Nos testes, o HTTP e
 * simulado (Http::fake); nada sai da maquina.
 */
class StripeClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.stripe.secret')) !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->send('post', '/v1/checkout/sessions', $params, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    public function expireCheckoutSession(string $sessionId, string $idempotencyKey): array
    {
        return $this->send('post', '/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire', [], $idempotencyKey);
    }

    /**
     * Cancelar no fim do periodo (true) ou desfazer o cancelamento (false).
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel, string $idempotencyKey): array
    {
        return $this->send('post', '/v1/subscriptions/'.rawurlencode($subscriptionId), ['cancel_at_period_end' => $cancel ? 'true' : 'false'], $idempotencyKey);
    }

    /**
     * Cancelamento imediato (o Stripe encerra agora, sem nova cobranca).
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    public function cancelNow(string $subscriptionId, string $idempotencyKey): array
    {
        return $this->send('delete', '/v1/subscriptions/'.rawurlencode($subscriptionId), [], $idempotencyKey);
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    public function refund(string $paymentIntentId, int $amountCents, array $metadata, string $idempotencyKey): array
    {
        return $this->send('post', '/v1/refunds', ['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'metadata' => $metadata], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    private function send(string $method, string $path, array $params, string $idempotencyKey): array
    {
        if (! $this->isConfigured()) {
            throw new SubscriptionRuleViolation('stripe_not_configured');
        }
        try {
            $resposta = $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->send(strtoupper($method), $path, $params === [] ? [] : ['form_params' => $params]);
        } catch (ConnectionException) {
            Log::warning('Stripe indisponível', ['caminho' => $path]);
            throw new SubscriptionRuleViolation('gateway_error');
        }

        return $this->decode($resposta, $path);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.stripe.api_base'))
            ->withToken((string) config('services.stripe.secret'))
            ->withHeaders(['Stripe-Version' => (string) config('services.stripe.api_version')])
            ->asForm()
            ->acceptJson()
            ->timeout((int) config('services.stripe.timeout', 15));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SubscriptionRuleViolation
     */
    private function decode(Response $resposta, string $path): array
    {
        $corpo = $resposta->json();
        if ($resposta->successful() && is_array($corpo)) {
            return $corpo;
        }
        // So o tipo e o codigo do erro (a mensagem do Stripe pode citar dados do cliente; nunca a chave).
        $erro = is_array($corpo) && is_array($corpo['error'] ?? null) ? $corpo['error'] : [];
        Log::warning('Stripe recusou a chamada', ['caminho' => $path, 'status' => $resposta->status(), 'tipo' => $erro['type'] ?? null, 'codigo' => $erro['code'] ?? null]);

        throw new SubscriptionRuleViolation('gateway_error', isset($erro['code']) ? '(código '.$erro['code'].')' : null);
    }
}

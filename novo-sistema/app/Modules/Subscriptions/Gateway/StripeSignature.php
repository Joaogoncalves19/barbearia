<?php

namespace App\Modules\Subscriptions\Gateway;

use App\Modules\Subscriptions\Exceptions\InvalidWebhook;

/**
 * Verificacao da assinatura do webhook do Stripe (webhooks.md §2), conforme
 * a especificacao publica do Stripe: o cabecalho Stripe-Signature traz
 * `t=<unix>` e um ou mais `v1=<hex>`; o esperado e HMAC-SHA256 de
 * "<t>.<corpo cru>" com o segredo do endpoint. Comparacao em tempo constante.
 *
 * Protecao contra replay: o instante `t` precisa estar dentro da janela
 * (padrao 5 min) em relacao ao relogio do servidor; um evento capturado e
 * reenviado depois disso e recusado. Dentro da janela, o mesmo evento
 * reenviado e reconhecido pelo ID (idempotencia em StripeWebhook).
 */
final class StripeSignature
{
    /**
     * @throws InvalidWebhook
     */
    public static function verify(string $payload, string $header, string $secret, int $toleranceSeconds, int $now): void
    {
        if ($secret === '') {
            throw new InvalidWebhook('not_configured');
        }
        $t = null;
        $assinaturas = [];
        foreach (explode(',', $header) as $parte) {
            [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($chave === 't' && ctype_digit($valor)) {
                $t = (int) $valor;
            } elseif ($chave === 'v1' && $valor !== '') {
                $assinaturas[] = $valor;
            }
        }
        if ($t === null || $assinaturas === []) {
            throw new InvalidWebhook('bad_header');
        }
        if (abs($now - $t) > $toleranceSeconds) {
            throw new InvalidWebhook('outside_tolerance');
        }
        $esperada = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($assinaturas as $a) {
            if (hash_equals($esperada, $a)) {
                return;
            }
        }
        throw new InvalidWebhook('bad_signature');
    }

    /** Cabecalho valido para um corpo (usado pelos testes e pela sonda de concorrencia). */
    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}

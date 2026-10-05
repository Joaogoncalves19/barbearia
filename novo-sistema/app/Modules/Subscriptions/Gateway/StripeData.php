<?php

namespace App\Modules\Subscriptions\Gateway;

/**
 * Leitura tolerante dos objetos do Stripe (stripe.md §4). A versao da API e
 * fixada, mas campos mudaram de lugar entre versoes (ex.: current_period_end
 * foi para os itens; a assinatura da fatura foi para parent.subscription_details).
 * Cada leitura tenta o formato da versao fixada e depois os formatos novos,
 * como o sistema antigo ja fazia para o fim do periodo.
 */
final class StripeData
{
    /**
     * @param  array<string, mixed>  $a
     */
    public static function get(array $a, string $path): mixed
    {
        $v = $a;
        foreach (explode('.', $path) as $k) {
            if (! is_array($v) || ! array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }

        return $v;
    }

    /**
     * Primeiro valor nao vazio entre os caminhos.
     *
     * @param  array<string, mixed>  $a
     * @param  list<string>  $paths
     */
    public static function first(array $a, array $paths): mixed
    {
        foreach ($paths as $p) {
            $v = self::get($a, $p);
            if ($v !== null && $v !== '' && $v !== []) {
                return $v;
            }
        }

        return null;
    }

    /** ID de um campo que pode vir como texto ou como objeto expandido. */
    public static function id(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = $v['id'] ?? null;
        }

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public static function periodEnd(array $subscription): ?int
    {
        $v = self::first($subscription, ['current_period_end', 'items.data.0.current_period_end']);

        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * Assinatura do Stripe a que a fatura pertence.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function invoiceSubscription(array $invoice): ?string
    {
        return self::id(self::first($invoice, ['subscription', 'parent.subscription_details.subscription', 'lines.data.0.subscription', 'lines.data.0.parent.subscription_item_details.subscription']));
    }

    /**
     * Metadado "local_subscription" (identificador publico da assinatura local)
     * onde quer que venha: no objeto, nos detalhes da assinatura da fatura ou
     * nas linhas.
     *
     * @param  array<string, mixed>  $object
     */
    public static function localId(array $object): ?string
    {
        $v = self::first($object, [
            'metadata.local_subscription',
            'client_reference_id',
            'subscription_details.metadata.local_subscription',
            'parent.subscription_details.metadata.local_subscription',
            'lines.data.0.metadata.local_subscription',
            'subscription_data.metadata.local_subscription',
        ]);

        return is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 ? strtolower($v) : null;
    }

    /**
     * Periodo pago pela fatura [inicio, fim] (unix).
     *
     * @param  array<string, mixed>  $invoice
     * @return array{0: ?int, 1: ?int}
     */
    public static function invoicePeriod(array $invoice): array
    {
        $ini = self::first($invoice, ['lines.data.0.period.start', 'period_start']);
        $fim = self::first($invoice, ['lines.data.0.period.end', 'period_end']);

        return [is_numeric($ini) ? (int) $ini : null, is_numeric($fim) ? (int) $fim : null];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public static function invoicePaymentIntent(array $invoice): ?string
    {
        return self::id(self::first($invoice, ['payment_intent', 'payments.data.0.payment.payment_intent']));
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public static function invoiceCharge(array $invoice): ?string
    {
        return self::id(self::first($invoice, ['charge', 'payments.data.0.payment.charge']));
    }
}

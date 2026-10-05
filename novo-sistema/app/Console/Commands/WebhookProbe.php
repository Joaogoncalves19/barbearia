<?php

namespace App\Console\Commands;

use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Gateway\StripeSignature;
use App\Modules\Subscriptions\Services\StripeWebhook;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sonda do TESTE DE CONCORRENCIA de webhooks (WebhookConcurrencyTest): cada
 * processo entrega um evento (arquivo JSON) pelo MESMO caminho do endpoint
 * (assinatura conferida, evento guardado, processamento transacional).
 * Espera um arquivo de "largada" e segura a transacao aberta (--hold) para
 * as entregas se cruzarem. So roda em local/testing; o segredo vem do
 * ambiente do teste.
 */
class WebhookProbe extends Command
{
    protected $signature = 'app:webhook-probe {payload : arquivo com o evento} {--barrier=} {--hold=300}';

    protected $description = 'Entrega um evento do Stripe (so para o teste de concorrencia)';

    public function handle(StripeWebhook $webhook): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }
        $barreira = (string) $this->option('barrier');
        $limite = microtime(true) + 20;
        while ($barreira !== '' && ! file_exists($barreira) && microtime(true) < $limite) {
            usleep(5000);
        }
        $hold = (int) $this->option('hold');
        StripeWebhook::$duringProcessing = fn () => usleep($hold * 1000);

        $corpo = (string) file_get_contents((string) $this->argument('payload'));
        try {
            $r = $webhook->receive($corpo, StripeSignature::header($corpo, (string) config('services.stripe.webhook_secret'), BusinessTime::now()->getTimestamp()));
            $this->line('OK '.$r['result']);
        } catch (Throwable $e) {
            $this->line('ERROR '.class_basename($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}

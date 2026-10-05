<?php

namespace App\Console\Commands;

use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Services\StripeWebhook;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reprocessamento seguro de eventos do Stripe ja guardados (webhooks.md §5):
 * um evento pelo ID, ou todos os que falharam (--failed) ou que chegaram sem
 * assinatura local (--unmatched). Evento ja processado e aplicado nao roda de
 * novo (idempotente); cada efeito e unico no banco.
 */
class StripeReprocess extends Command
{
    protected $signature = 'app:stripe-reprocess
        {event? : ID do evento no Stripe (evt_...)}
        {--failed : todos os que falharam}
        {--unmatched : todos os sem assinatura local}';

    protected $description = 'Reprocessa eventos do Stripe guardados (falhos ou sem assinatura local)';

    public function handle(StripeWebhook $webhook): int
    {
        $q = GatewayEvent::query()->where('gateway', 'stripe')->orderBy('event_created_at')->orderBy('id');
        if ($this->argument('event') !== null) {
            $q->where('event_id', (string) $this->argument('event'));
        } elseif ($this->option('failed')) {
            $q->whereIn('status', ['failed', 'received']);
        } elseif ($this->option('unmatched')) {
            $q->where('result', 'unmatched');
        } else {
            $this->error('Informe o ID do evento, --failed ou --unmatched.');

            return self::FAILURE;
        }

        $ok = 0;
        $erros = 0;
        foreach ($q->get() as $e) {
            if ($e->result === 'unmatched') {
                GatewayEvent::query()->whereKey($e->id)->update(['status' => 'received', 'result' => null]);
                $e->refresh();
            }
            try {
                $r = $webhook->process($e);
                $this->line($e->event_id.' '.$e->type.': '.$r);
                $ok++;
            } catch (Throwable $t) {
                $this->line($e->event_id.' '.$e->type.': FALHOU ('.class_basename($t).')');
                $erros++;
            }
        }
        $this->info("Processados: {$ok}; falhas: {$erros}.");

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }
}

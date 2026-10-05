<?php

namespace App\Console\Commands;

use App\Modules\Subscriptions\Services\SubscriptionManager;
use Illuminate\Console\Command;

/**
 * Rotina diaria de assinaturas (assinaturas.md §6): expira links de
 * pagamento vencidos e assinaturas MANUAIS (importadas) que passaram do fim
 * pago mais a tolerancia (R-28). Idempotente: rodar de novo nao muda nada.
 * As assinaturas do Stripe seguem o estado consolidado do Stripe.
 */
class SubscriptionsExpire extends Command
{
    protected $signature = 'app:subscriptions-expire';

    protected $description = 'Expira links de pagamento vencidos e assinaturas manuais vencidas';

    public function handle(SubscriptionManager $manager): int
    {
        $n = $manager->expireDue();
        $this->info("Links expirados: {$n['links']}; assinaturas expiradas: {$n['expiradas']}; canceladas no fim do período: {$n['canceladas']}.");

        return self::SUCCESS;
    }
}

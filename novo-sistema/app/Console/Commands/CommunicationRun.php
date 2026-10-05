<?php

namespace App\Console\Commands;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\Outbox;
use App\Modules\Communication\Services\Reminders;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Reviews\Services\ReviewRequests;
use App\Modules\Subscriptions\Services\GatewayEventRetention;
use Illuminate\Console\Command;

/**
 * Rotinas de comunicacao (lembretes.md, campanhas.md, avaliacoes.md, fila.md),
 * todas idempotentes (rodar duas vezes nao duplica nada):
 *   reminders        lembretes (vespera e horas antes)
 *   review-requests  pedidos de avaliacao
 *   campaigns        proximo lote das campanhas em envio
 *   retry            reenvia e-mails que falharam (--id= um so)
 *   retention        retencao dos eventos do Stripe (P9-10)
 */
class CommunicationRun extends Command
{
    protected $signature = 'app:communication {task : reminders|review-requests|campaigns|retry|retention} {--id= : registro de e-mail (retry)}';

    protected $description = 'Rotinas de comunicação (lembretes, avaliações, campanhas, reenvio, retenção)';

    public function handle(): int
    {
        switch ((string) $this->argument('task')) {
            case 'reminders':
                $n = app(Reminders::class)->run();
                $this->info("Lembretes: véspera {$n['day_before']}, horas antes {$n['hours_before']}, já lembrados {$n['ja_lembrados']}.");
                break;
            case 'review-requests':
                $this->info('Pedidos de avaliação: '.app(ReviewRequests::class)->run().'.');
                break;
            case 'campaigns':
                $n = app(Campaigns::class)->processBatch();
                $this->info("Campanhas: {$n['entregues']} entregues à fila, {$n['pulados']} pulados, {$n['concluidas']} concluídas.");
                break;
            case 'retry':
                $q = EmailMessage::query()->where('status', 'failed');
                if ($this->option('id') !== null) {
                    $q->where('public_id', (string) $this->option('id'));
                }
                $n = 0;
                foreach ($q->get() as $m) {
                    $n += app(Outbox::class)->retry($m) ? 1 : 0;
                }
                $this->info("E-mails reenviados para a fila: {$n}.");
                break;
            case 'retention':
                $this->info('Eventos do Stripe anonimizados: '.app(GatewayEventRetention::class)->run().'.');
                break;
            default:
                $this->error('Tarefa desconhecida.');

                return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

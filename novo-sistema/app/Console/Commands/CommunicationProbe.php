<?php

namespace App\Console\Commands;

use App\Modules\Communication\Services\Reminders;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Reviews\Services\ReviewRequests;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sonda do TESTE DE CONCORRENCIA da comunicacao (CommunicationConcurrencyTest):
 * varios processos rodam a MESMA rotina (lembretes, pedidos de avaliacao,
 * lote de campanha) ao mesmo tempo, como dois agendadores/servidores.
 * Espera um arquivo de "largada" para as execucoes se cruzarem. So roda em
 * local/testing.
 */
class CommunicationProbe extends Command
{
    protected $signature = 'app:communication-probe {task : reminders|review-requests|campaigns} {--barrier=}';

    protected $description = 'Roda uma rotina de comunicação (só para o teste de concorrência)';

    public function handle(): int
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

        try {
            $r = match ((string) $this->argument('task')) {
                'reminders' => app(Reminders::class)->run(),
                'review-requests' => ['pedidos' => app(ReviewRequests::class)->run()],
                'campaigns' => app(Campaigns::class)->processBatch(),
                default => throw new \InvalidArgumentException('Tarefa desconhecida.'),
            };
            $this->line('OK '.json_encode($r));
        } catch (Throwable $e) {
            $this->line('ERROR '.class_basename($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}

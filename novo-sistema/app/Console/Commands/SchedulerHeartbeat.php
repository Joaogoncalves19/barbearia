<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Registra que o agendador esta vivo. Roda a cada minuto (routes/console.php).
 *
 * No sistema antigo, lembretes dependiam de um cron que provavelmente nunca
 * foi configurado, e ninguem percebia. Aqui o diagnostico (app:diagnose) e,
 * no futuro, a tela de saude do painel mostram quando o batimento parou.
 */
#[Signature('app:scheduler-heartbeat')]
#[Description('Registra o batimento do agendador (usado pelo diagnostico)')]
class SchedulerHeartbeat extends Command
{
    public const CACHE_KEY = 'scheduler:last_heartbeat';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}

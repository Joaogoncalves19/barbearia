<?php

use App\Console\Commands\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tarefas agendadas
|--------------------------------------------------------------------------
|
| Um unico cron no servidor aciona tudo:
|
|     * * * * * cd /caminho/novo-sistema && php artisan schedule:run >> /dev/null 2>&1
|
| Tarefas de NEGOCIO (lembretes, expiracao de assinaturas, despesas
| recorrentes, campanhas) entram aqui nas fases correspondentes, sempre como
| jobs idempotentes que estendem App\Modules\Shared\Jobs\BaseJob.
|
*/

// Batimento: permite saber se o cron esta configurado (app:diagnose).
Schedule::command(SchedulerHeartbeat::class)->everyMinute();

// Manutencao da fila.
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();

// Hospedagem sem supervisor de processos: o proprio agendador processa a
// fila a cada minuto e encerra antes do proximo ciclo.
if (config('barbearia.queue.work_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground();
}

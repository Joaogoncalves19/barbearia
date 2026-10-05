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

// Assinaturas (Fase 9): links vencidos e assinaturas manuais vencidas.
Schedule::command('app:subscriptions-expire')->dailyAt('03:10')->withoutOverlapping(30);

// Comunicacao (Fase 10): idempotentes; rodar duas vezes nao duplica nada.
Schedule::command('app:communication reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('app:communication review-requests')->everyTenMinutes()->withoutOverlapping(15);
Schedule::command('app:communication campaigns')->everyMinute()->withoutOverlapping(5);
// Retencao dos eventos do Stripe (P9-10: 12 meses).
Schedule::command('app:communication retention')->dailyAt('03:30')->withoutOverlapping(30);

// Manutencao da fila.
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();

// Hospedagem sem supervisor de processos: o proprio agendador processa a
// fila a cada minuto e encerra antes do proximo ciclo.
if (config('barbearia.queue.work_via_scheduler')) {
    Schedule::command('queue:work --queue=emails,default --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground();
}

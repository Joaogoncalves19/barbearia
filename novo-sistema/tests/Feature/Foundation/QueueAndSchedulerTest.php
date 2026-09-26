<?php

namespace Tests\Feature\Foundation;

use App\Console\Commands\SchedulerHeartbeat;
use App\Modules\Shared\Jobs\BaseJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class JobDeTesteQueFalha extends BaseJob
{
    public function handle(): void
    {
        throw new RuntimeException('falha proposital');
    }
}

class QueueAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_base_tem_retentativas_espera_crescente_e_timeout(): void
    {
        $job = new JobDeTesteQueFalha;

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertGreaterThan(0, $job->timeout);
    }

    public function test_falha_de_job_e_registrada_no_canal_de_jobs(): void
    {
        Log::shouldReceive('channel')->with('jobs')->atLeast()->once()->andReturnSelf();
        Log::shouldReceive('error')->atLeast()->once();

        try {
            dispatch(new JobDeTesteQueFalha);
        } catch (RuntimeException) {
            // conexao sync propaga a excecao; o importante e o registro.
        }

        (new JobDeTesteQueFalha)->failed(new RuntimeException('falha proposital'));
    }

    public function test_fila_padrao_do_ambiente_real_e_o_banco(): void
    {
        $exemplo = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=database$/m', $exemplo);
    }

    public function test_agendador_registra_batimento_e_manutencao_da_fila(): void
    {
        $comandos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        $this->assertStringContainsString('app:scheduler-heartbeat', $comandos);
        $this->assertStringContainsString('queue:prune-failed', $comandos);
    }

    public function test_comando_de_batimento_grava_o_horario(): void
    {
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        $this->artisan('app:scheduler-heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get(SchedulerHeartbeat::CACHE_KEY));
    }

    public function test_diagnostico_roda_e_nao_expoe_segredos(): void
    {
        $this->artisan('app:diagnose')
            ->expectsOutputToContain('Conexão com o banco')
            ->doesntExpectOutputToContain((string) config('app.key'))
            ->assertSuccessful();
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Verificacao rapida do ambiente, para rodar apos cada deploy:
 *
 *     php artisan app:diagnose
 *
 * Sai com codigo 1 se algum item CRITICO falhar. Nunca imprime segredos:
 * so informa se estao presentes.
 */
#[Signature('app:diagnose')]
#[Description('Verifica configuracao, banco, fila e agendador do ambiente')]
class Diagnose extends Command
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $linhas = [];

    private bool $falhouCritico = false;

    public function handle(): int
    {
        $producao = app()->environment(['production', 'homologacao']);

        $this->item('APP_KEY definida', filled(config('app.key')), true);
        $this->item('APP_URL definida', filled(config('app.url')), true);
        $this->item('APP_URL usa HTTPS', str_starts_with((string) config('app.url'), 'https://'), $producao);
        $this->item('APP_DEBUG desligado', ! config('app.debug'), $producao);
        $this->item('Cookie de sessao so em HTTPS', (bool) config('session.secure'), $producao);
        $this->item('Protótipos desligados', ! config('barbearia.prototypes_enabled'), app()->isProduction());

        try {
            DB::connection()->getPdo();
            $this->item('Conexão com o banco ('.config('database.default').')', true, true);
            $this->item('Migrations aplicadas (tabela users existe)', Schema::hasTable('users'), true);
            $this->item('Tabela de jobs existe', Schema::hasTable('jobs'), true);
            $falhos = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $this->item("Jobs com falha: {$falhos}", $falhos === 0, false);
        } catch (Throwable $e) {
            $this->item('Conexão com o banco: '.class_basename($e), false, true);
        }

        $batimento = Cache::get(SchedulerHeartbeat::CACHE_KEY);
        $tolerancia = (int) config('barbearia.scheduler.heartbeat_tolerance_minutes', 5);
        $vivo = $batimento && Carbon::parse($batimento)->gt(now()->subMinutes($tolerancia));
        $this->item('Agendador ativo (batimento < '.$tolerancia.' min)', $vivo, false,
            $batimento ? 'último: '.$batimento : 'nunca rodou — configure o cron');

        $this->item('E-mail configurado (MAIL_MAILER='.config('mail.default').')',
            ! in_array(config('mail.default'), ['log', 'array'], true), $producao);

        $this->table(['Situação', 'Verificação', 'Detalhe'], $this->linhas);

        return $this->falhouCritico ? self::FAILURE : self::SUCCESS;
    }

    private function item(string $descricao, bool $ok, bool $critico, string $detalhe = ''): void
    {
        $situacao = $ok ? 'OK' : ($critico ? 'FALHA' : 'AVISO');
        if (! $ok && $critico) {
            $this->falhouCritico = true;
        }
        $this->linhas[] = [$situacao, $descricao, $detalhe];
    }
}

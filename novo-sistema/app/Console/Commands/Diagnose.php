<?php

namespace App\Console\Commands;

use App\Modules\System\Backup\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Verificacao rapida do ambiente, para rodar apos cada deploy:
 *
 *     php artisan app:diagnose
 *
 * Sai com codigo 1 se algum item CRITICO falhar. Nunca imprime segredos:
 * so informa se estao presentes.
 *
 * Com --alert (agendador, a cada hora; Fase 13) registra os problemas no log
 * e manda um e-mail curto para MONITOR_EMAIL, no maximo uma vez a cada 6 h
 * para o mesmo conjunto de problemas.
 */
#[Signature('app:diagnose {--alert : Registra no log e avisa MONITOR_EMAIL se houver problema}')]
#[Description('Verifica configuracao, banco, fila, agendador e copias de seguranca do ambiente')]
class Diagnose extends Command
{
    public const ALERT_CACHE_PREFIX = 'monitor:alerted:';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $linhas = [];

    private bool $falhouCritico = false;

    /** @var list<string> */
    private array $problemas = [];

    public function handle(BackupManager $backups): int
    {
        // O comando e reaproveitado na mesma execucao (agendador, testes).
        $this->linhas = [];
        $this->problemas = [];
        $this->falhouCritico = false;
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
            $this->item("Jobs com falha: {$falhos}", $falhos === 0, false, monitorar: true);
            if (Schema::hasTable('email_messages')) {
                $parados = DB::table('email_messages')->where('status', 'queued')->where('queued_at', '<', now()->subHours(2))->count();
                $this->item("E-mails na fila há mais de 2 h: {$parados}", $parados === 0, false, $parados ? 'worker parado? confira o cron e a fila' : '', true);
                $falharam = DB::table('email_messages')->where('status', 'failed')->count();
                $this->item("E-mails que falharam: {$falharam}", $falharam === 0, false, $falharam ? 'Painel → Comunicação → E-mails enviados' : '', true);
            }
        } catch (Throwable $e) {
            $this->item('Conexão com o banco: '.class_basename($e), false, true);
        }

        $batimento = Cache::get(SchedulerHeartbeat::CACHE_KEY);
        $tolerancia = (int) config('barbearia.scheduler.heartbeat_tolerance_minutes', 5);
        $vivo = $batimento && Carbon::parse($batimento)->gt(now()->subMinutes($tolerancia));
        $this->item('Agendador ativo (batimento < '.$tolerancia.' min)', $vivo, false,
            $batimento ? 'último: '.$batimento : 'nunca rodou — configure o cron', true);

        $mailer = (string) config('mail.default');
        $this->item('E-mail configurado (MAIL_MAILER='.$mailer.')', ! in_array($mailer, ['log', 'array'], true), $producao);
        if ($mailer === 'resend') {
            // O transporte "resend" do Laravel precisa de um pacote que nao faz
            // parte da instalacao: use smtp (inclusive o SMTP do Resend).
            $this->item('Transporte de e-mail instalado', class_exists('Resend'), true, 'use MAIL_MAILER=smtp');
        }

        $idade = $backups->latestAgeHours();
        $limite = (int) config('barbearia.backup.max_age_hours', 26);
        $this->item('Cópia de segurança recente (< '.$limite.' h)', $idade !== null && $idade < $limite, false,
            $idade === null ? 'nenhuma cópia em '.$backups->directory() : 'última há '.number_format($idade, 1, ',', '.').' h', true);
        $this->item('Cópia cifrada (BACKUP_PASSWORD)', filled(config('barbearia.backup.password')), false);

        $this->table(['Situação', 'Verificação', 'Detalhe'], $this->linhas);

        if ($this->option('alert')) {
            $this->notifyMonitor();
        }

        return $this->falhouCritico ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  bool  $monitorar  problema de OPERACAO (agendador, fila, copia):
     *                           entra no aviso mesmo quando nao e critico
     */
    private function item(string $descricao, bool $ok, bool $critico, string $detalhe = '', bool $monitorar = false): void
    {
        $situacao = $ok ? 'OK' : ($critico ? 'FALHA' : 'AVISO');
        if (! $ok && $critico) {
            $this->falhouCritico = true;
        }
        if (! $ok && ($critico || $monitorar)) {
            $this->problemas[] = $situacao.': '.$descricao.($detalhe !== '' ? ' ('.$detalhe.')' : '');
        }
        $this->linhas[] = [$situacao, $descricao, $detalhe];
    }

    private function notifyMonitor(): void
    {
        // So o que pede acao de alguem: falha critica ou problema de operacao.
        $problemas = $this->problemas;
        if ($problemas === []) {
            return;
        }
        Log::critical('monitor.problemas', ['problemas' => $problemas]);

        $destino = (string) config('barbearia.monitor.email');
        $chave = self::ALERT_CACHE_PREFIX.sha1(implode('|', array_map(fn (string $p) => preg_replace('/\d+/', '#', $p), $problemas)));
        if ($destino === '' || ! Cache::add($chave, now()->toIso8601String(), now()->addHours(6))) {
            return;
        }
        try {
            Mail::raw(
                "O diagnóstico da instalação encontrou:\n\n- ".implode("\n- ", $problemas)
                ."\n\nRode `php artisan app:diagnose` no servidor para o quadro completo.\n".config('app.url'),
                fn ($m) => $m->to($destino)->subject('['.config('app.name').'] Atenção na instalação')
            );
            $this->line('Aviso enviado para o monitoramento.');
        } catch (Throwable $e) {
            Log::critical('monitor.aviso_nao_enviado', ['erro' => class_basename($e)]);
        }
    }
}

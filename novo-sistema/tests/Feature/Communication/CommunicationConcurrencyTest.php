<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentReminder;
use App\Modules\System\Integrity\IntegrityChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONCORRENCIA DE VERDADE na comunicacao: processos PHP independentes (cada
 * um com a sua conexao, SQLite em arquivo) rodam a MESMA rotina ao mesmo
 * tempo, como dois agendadores ou dois servidores. Esperado: um lembrete por
 * horario, um e-mail por destinatario de campanha, nada pela metade.
 */
class CommunicationConcurrencyTest extends TestCase
{
    private string $dir;

    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'comunicacao-concorrencia-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.DIRECTORY_SEPARATOR.'comunicacao.sqlite';
        touch($this->db);
        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_agendador_rodando_em_quatro_processos_lembra_cada_horario_uma_vez(): void
    {
        // Horarios na janela "algumas horas antes" do relogio REAL dos processos.
        // A vespera fica desligada: perto da meia-noite estes horarios caem
        // "amanha" e o lembrete de vespera tambem sairia (correto, mas e outro
        // lembrete e o teste dependeria da hora em que roda).
        CommunicationSettings::save(['reminder_day_before_enabled' => false], null);
        $ags = collect(range(1, 3))->map(fn ($i) => Appointment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'starts_at' => CarbonImmutable::now()->addMinutes(30 + 20 * $i)->startOfMinute(),
            'ends_at' => CarbonImmutable::now()->addMinutes(60 + 20 * $i)->startOfMinute(),
            'source' => 'online',
        ]));

        $saidas = $this->race(array_fill(0, 4, 'reminders'));

        $this->assertCount(4, array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')), implode("\n", $saidas));
        DB::purge('sqlite');
        foreach ($ags as $a) {
            $this->assertSame(1, AppointmentReminder::query()->where('appointment_id', $a->id)->where('kind', 'hours_before')->count(), implode("\n", $saidas));
        }
        $this->assertSame(3, EmailMessage::query()->where('template', 'reminder_hours_before')->count());
        $this->assertSame(3, EmailMessage::query()->where('template', 'reminder_hours_before')->where('status', 'sent')->count());
        $this->assertSame(3, CustomerNotification::query()->where('kind', 'reminder')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_lote_de_campanha_em_quatro_processos_nao_duplica_email(): void
    {
        foreach (range(1, 12) as $i) {
            $c = Customer::factory()->create();
            $c->forceFill(['marketing_email_consent' => MarketingConsent::Granted])->save();
        }
        $gerente = User::factory()->manager()->create();
        $campanhas = app(Campaigns::class);
        $c = $campanhas->start($campanhas->saveDraft(null, 'Teste', 'Oi', 'Oi, {primeiro_nome}', 'todos', [], $gerente), $gerente, (string) Str::uuid());

        $saidas = $this->race(array_fill(0, 4, 'campaigns'));

        $this->assertCount(4, array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')), implode("\n", $saidas));
        DB::purge('sqlite');
        $this->assertSame(12, EmailMessage::query()->where('campaign_id', $c->id)->count(), implode("\n", $saidas));
        $this->assertSame(12, EmailMessage::query()->where('campaign_id', $c->id)->distinct()->count('customer_id'));
        $this->assertSame(12, CampaignRecipient::query()->where('campaign_id', $c->id)->where('status', 'dispatched')->count());
        $this->assertSame('completed', Campaign::query()->find($c->id)?->status);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    /**
     * @param  list<string>  $tasks
     * @return list<string>
     */
    private function race(array $tasks): array
    {
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada';
        $processos = array_map(fn ($t) => $this->spawn([$t, "--barrier={$largada}"]), $tasks);
        usleep(1_500_000);
        touch($largada);

        return array_map(fn ($p) => $this->finish($p), $processos);
    }

    /**
     * @param  list<string>  $args
     * @return array{proc: resource, out: resource, err: resource}
     */
    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'app:communication-probe'], $args);
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc);

        return ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
    }

    /**
     * @param  array{proc: resource, out: resource, err: resource}  $p
     */
    private function finish(array $p): string
    {
        $saida = trim((string) stream_get_contents($p['out']));
        $erro = trim((string) stream_get_contents($p['err']));
        fclose($p['out']);
        fclose($p['err']);
        proc_close($p['proc']);

        return $saida !== '' ? $saida : 'SEM SAIDA: '.$erro;
    }
}

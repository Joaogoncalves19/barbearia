<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Scheduling\Models\BusinessHour;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONCORRENCIA DE VERDADE (item 12/31 do briefing): varios processos PHP
 * independentes, cada um com a sua conexao, tentam reservar o MESMO horario
 * do MESMO profissional ao mesmo tempo, num banco SQLite em arquivo (como em
 * producao). Cada processo segura a transacao aberta entre a verificacao e a
 * gravacao para garantir que as tentativas se cruzem.
 *
 * Esperado: exatamente UMA reserva vence; as outras recebem conflito; o banco
 * nao fica com nada gravado pela metade.
 */
class BookingConcurrencyTest extends TestCase
{
    private string $dir;

    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agenda-concorrencia-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.DIRECTORY_SEPARATOR.'agenda.sqlite';
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

    public function test_so_uma_reserva_vence_quando_varias_chegam_juntas(): void
    {
        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
        }
        $servico = Service::factory()->create(['duration_minutes' => 30]);
        $pro = Professional::factory()->create();
        $pro->services()->attach($servico->id);
        $clientes = Customer::factory()->count(4)->create();

        // Os processos filhos usam o relogio real: horario daqui a 3 dias.
        $dia = CarbonImmutable::now(BusinessTime::zone())->addDays(3)->toDateString();
        $inicio = BusinessTime::at($dia, '10:00');
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada';

        $processos = [];
        foreach ($clientes as $i => $c) {
            $processos[] = $this->spawn([$servico->id, $pro->id, $c->id, $inicio->toIso8601String(), "--barrier={$largada}", '--hold=400']);
        }

        usleep(1_500_000); // todos sobem e ficam esperando a largada
        touch($largada);

        $saidas = array_map(fn ($p) => $this->finish($p), $processos);

        $ok = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')));
        $conflitos = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'CONFLICT conflict')));

        $this->assertCount(1, $ok, "exatamente uma reserva vence:\n".implode("\n", $saidas));
        $this->assertCount(3, $conflitos, "as outras recebem conflito:\n".implode("\n", $saidas));

        DB::purge('sqlite');
        $this->assertSame(1, Appointment::query()->where('professional_id', $pro->id)->count(), 'uma reserva no banco');
        $this->assertSame(1, AppointmentItem::query()->count(), 'nada gravado pela metade');
        // So a transacao vencedora ficou: as perdedoras foram desfeitas por inteiro
        // (inclusive a escrita de bloqueio).
        $this->assertSame(1, (int) DB::table('professionals')->where('id', $pro->id)->value('schedule_version'));
    }

    /**
     * Encaixe da recepcao x agendamento pelo site, ao mesmo tempo, no mesmo
     * profissional e em horarios que se sobrepoem: o encaixe passa pelo
     * mesmo BookingService (mesma trava da agenda), entao so UM vence, seja
     * ele qual for, e o perdedor nao deixa nada gravado (nem atendimento).
     */
    public function test_encaixe_e_site_disputando_o_mesmo_horario(): void
    {
        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create(['weekday' => $d, 'starts_at' => '09:00', 'ends_at' => '20:00']);
        }
        BookingPolicy::save(['min_notice_minutes' => 0]);
        $servico = Service::factory()->create(['duration_minutes' => 30]);
        $pro = Professional::factory()->create();
        $pro->services()->attach($servico->id);
        $recepcao = User::factory()->create();
        $clientes = Customer::factory()->count(6)->create();

        // Relogio parado nos filhos: segunda 10:02:20; o encaixe ocupa 10:05-10:35.
        $agora = BusinessTime::at('2026-10-05', '10:02')->addSeconds(20)->toIso8601String();
        $largada = $this->dir.DIRECTORY_SEPARATOR.'largada-mista';
        $comum = ["--barrier={$largada}", '--hold=400', "--now={$agora}"];

        $processos = [];
        foreach ([['walk-in', '10:05'], ['online', '10:05'], ['walk-in', '10:05'], ['online', '10:20'], ['walk-in', '10:05'], ['online', '10:30']] as $i => [$como, $hora]) {
            $processos[] = $this->spawn([$servico->id, $pro->id, $clientes[$i]->id, BusinessTime::at('2026-10-05', $hora)->toIso8601String(),
                "--as={$como}", "--actor={$recepcao->id}", ...$comum]);
        }

        usleep(1_500_000);
        touch($largada);

        $saidas = array_map(fn ($p) => $this->finish($p), $processos);
        $ok = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'OK')));
        $conflitos = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'CONFLICT conflict')));

        $this->assertCount(1, $ok, "exatamente um vence:\n".implode("\n", $saidas));
        $this->assertCount(5, $conflitos, "os outros recebem conflito:\n".implode("\n", $saidas));

        DB::purge('sqlite');
        $this->assertSame(1, Appointment::query()->where('professional_id', $pro->id)->count(), 'uma reserva no banco');
        $this->assertSame(1, AppointmentItem::query()->count(), 'nada gravado pela metade');
        $encaixes = Appointment::query()->where('source', 'walk_in')->count();
        $this->assertSame($encaixes, Attendance::query()->count(), 'atendimento só se o encaixe venceu');
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    /**
     * @param  list<int|string>  $args
     * @return array{proc: resource, out: resource}
     */
    private function spawn(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'app:booking-probe'], array_map('strval', $args));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->db,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
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

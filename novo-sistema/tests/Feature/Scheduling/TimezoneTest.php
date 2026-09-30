<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * Politica de fuso (horarios.md): banco/PHP/Laravel em UTC; horas de parede e
 * datas no fuso da barbearia; conversao so no BusinessTime.
 */
class TimezoneTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    public function test_aplicacao_e_banco_em_utc_e_barbearia_em_sao_paulo(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', BusinessTime::zone());

        $a = $this->book($this->terca, '14:00');

        // 14:00 em Sao Paulo (UTC-3) = 17:00 UTC no banco.
        $this->assertSame('2026-10-06 17:00:00', DB::table('appointments')->where('id', $a->id)->value('starts_at'));
        $this->assertSame('06/10/2026 14:00', BusinessTime::formatLocal($a->starts_at));
    }

    public function test_fuso_da_maquina_nao_muda_nada(): void
    {
        $antes = $this->freeTimes($this->terca);
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Asia/Tokyo');
            $this->assertSame($antes, $this->freeTimes($this->terca));
            $this->assertSame('2026-10-06 17:00:00', $this->at($this->terca, '14:00')->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-05', BusinessTime::today(), 'hoje é o dia da barbearia, não o da máquina');
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_virada_de_dia_usa_o_calendario_da_barbearia(): void
    {
        // 02:30 UTC de terca ainda e segunda 23:30 em Sao Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 02:30:00', 'UTC'));

        $this->assertSame('2026-10-05', BusinessTime::today());
        $this->assertSame('2026-10-05', BusinessTime::dateOf(BusinessTime::now()));
    }

    public function test_horario_de_verao_nao_desloca_a_agenda(): void
    {
        // Fuso com horario de verao: EUA voltam 1 h em 01/11/2026.
        config(['barbearia.display_timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'));

        $this->assertSame('2026-10-31 13:00:00', BusinessTime::at('2026-10-31', '09:00')->format('Y-m-d H:i:s'), 'EDT (UTC-4)');
        $this->assertSame('2026-11-01 14:00:00', BusinessTime::at('2026-11-01', '09:00')->format('Y-m-d H:i:s'), 'EST (UTC-5)');

        // A grade continua comecando as 09:00 locais nos dois dias.
        $this->assertSame('09:00', $this->freeTimes('2026-10-31', channel: Channel::Staff)[0]);
        $this->assertSame('09:00', $this->freeTimes('2026-11-01', channel: Channel::Staff)[0]);
        $this->assertSame('19:30', collect($this->freeTimes('2026-11-01', channel: Channel::Staff))->last());
    }

    public function test_datas_invalidas_sao_recusadas(): void
    {
        $this->assertFalse(BusinessTime::isValidDate('2026-02-30'));
        $this->assertFalse(BusinessTime::isValidDate('06/10/2026'));
        $this->assertTrue(BusinessTime::isValidDate('2026-10-06'));
        $this->assertSame([], $this->availability()->slots($this->corte, $this->joao, '2026-13-01', Channel::Customer));
    }
}

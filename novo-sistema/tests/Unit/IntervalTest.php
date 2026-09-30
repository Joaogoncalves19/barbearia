<?php

namespace Tests\Unit;

use App\Modules\Scheduling\Support\Interval;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A unica regra de sobreposicao e de fim de atendimento: intervalos
 * meio-abertos [inicio, fim).
 */
class IntervalTest extends TestCase
{
    private function iv(string $de, string $ate): Interval
    {
        return new Interval(CarbonImmutable::parse("2026-10-06 {$de}", 'UTC'), CarbonImmutable::parse("2026-10-06 {$ate}", 'UTC'));
    }

    public function test_todos_os_tipos_de_sobreposicao(): void
    {
        $a = $this->iv('10:00', '10:30');

        $casos = [
            'adjacente depois (10:30-11:00)' => [$this->iv('10:30', '11:00'), false],
            'adjacente antes (09:30-10:00)' => [$this->iv('09:30', '10:00'), false],
            'comeca um minuto antes do fim (10:29-10:59)' => [$this->iv('10:29', '10:59'), true],
            'comeca antes e termina dentro (09:45-10:15)' => [$this->iv('09:45', '10:15'), true],
            'comeca dentro e termina depois (10:15-10:45)' => [$this->iv('10:15', '10:45'), true],
            'totalmente dentro (10:10-10:20)' => [$this->iv('10:10', '10:20'), true],
            'envolve o outro (09:00-11:00)' => [$this->iv('09:00', '11:00'), true],
            'identico (10:00-10:30)' => [$this->iv('10:00', '10:30'), true],
            'longe antes (08:00-09:00)' => [$this->iv('08:00', '09:00'), false],
            'longe depois (11:00-12:00)' => [$this->iv('11:00', '12:00'), false],
        ];

        foreach ($casos as $nome => [$b, $esperado]) {
            $this->assertSame($esperado, $a->overlaps($b), $nome);
            $this->assertSame($esperado, $b->overlaps($a), $nome.' (simetrico)');
        }
    }

    public function test_fim_calculado_num_lugar_so(): void
    {
        $i = Interval::starting(CarbonImmutable::parse('2026-10-06 17:00:00', 'UTC'), 40);
        $this->assertSame('17:40', $i->end->format('H:i'));
        $this->assertSame(40, $i->minutes());

        $longo = Interval::starting(CarbonImmutable::parse('2026-10-06 17:00:00', 'UTC'), 150);
        $this->assertSame('19:30', $longo->end->format('H:i'));
    }

    public function test_cabe_dentro(): void
    {
        $dia = $this->iv('09:00', '20:00');
        $this->assertTrue($this->iv('09:00', '09:30')->within($dia));
        $this->assertTrue($this->iv('19:30', '20:00')->within($dia));
        $this->assertFalse($this->iv('19:45', '20:15')->within($dia), 'atravessa o fechamento');
        $this->assertFalse($this->iv('08:45', '09:15')->within($dia));
    }

    public function test_intervalo_invalido_e_recusado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->iv('10:00', '10:00');
    }
}

<?php

namespace Tests\Feature\Operations;

use App\Modules\Customers\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Plano de retorno (Fase 13): o que o sistema novo gravou depois da virada
 * sai em CSV para a recepcao relancar no sistema antigo. Antes da virada
 * nada sai; o comando so le.
 */
class RollbackExportTest extends TestCase
{
    use CheckoutFixtures;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retorno-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return list<array<int, string>> */
    private function ler(string $arquivo): array
    {
        $conteudo = (string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.$arquivo);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo, 'UTF-8 com BOM, para abrir no Excel');
        $linhas = array_map(fn (string $l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", substr($conteudo, 3))));

        return array_values($linhas);
    }

    public function test_exporta_so_o_que_foi_gravado_depois_da_virada(): void
    {
        $antes = $this->todayAppointment('10:00');           // gravado as 08:00
        $this->clockAt('09:00');                             // virada as 08:30
        $depois = $this->todayAppointment('11:00');
        Customer::factory()->create(['name' => 'Cliente Novo Pós-Virada', 'phone' => '+5511987654321']);
        $this->openCash();
        $at = $this->attendances()->start($this->walkIn(), $this->recepcao);
        $this->attendances()->complete($at, $this->pay(5000, tip: 500), $this->key(), $this->recepcao);

        $this->artisan('app:rollback-export', ['--since' => '2026-10-05 08:30', '--dir' => $this->dir])
            ->expectsOutputToContain('Exportado em')
            ->assertSuccessful();

        $agendamentos = $this->ler('agendamentos.csv');
        $codigos = array_column(array_slice($agendamentos, 1), 0);
        $this->assertContains($depois->code, $codigos);
        $this->assertNotContains($antes->code, $codigos, 'Agendamento anterior à virada e não alterado não sai');
        $linha = $agendamentos[array_search($depois->code, array_column($agendamentos, 0), true)];
        $this->assertSame(['05/10/2026', '11:00'], [$linha[2], $linha[3]], 'Data e hora no fuso da barbearia');

        $atendimentos = $this->ler('atendimentos.csv');
        $this->assertCount(2, $atendimentos);
        $this->assertSame($at->code, $atendimentos[1][0]);
        $this->assertSame(['50,00', '5,00'], [$atendimentos[1][10], $atendimentos[1][11]]);

        $pagamentos = $this->ler('pagamentos.csv');
        $this->assertSame('50,00', $pagamentos[1][3]);

        $clientes = array_column(array_slice($this->ler('clientes.csv'), 1), 0);
        $this->assertContains('Cliente Novo Pós-Virada', $clientes);
        $telefone = $this->ler('clientes.csv')[array_search('Cliente Novo Pós-Virada', array_column($this->ler('clientes.csv'), 0), true)][1];
        $this->assertSame("'+5511987654321", $telefone, 'Célula iniciada por + não vira fórmula no Excel');

        $this->assertStringContainsString('agendamentos', (string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.'resumo.txt'));
        foreach (['caixa.csv', 'assinaturas.csv'] as $f) {
            $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.$f);
        }
        $this->assertStringNotContainsString((string) $this->cliente->cpf, implode('', array_map(fn ($f) => (string) file_get_contents($f), glob($this->dir.'/*') ?: [])), 'CPF não sai na exportação');
    }

    public function test_sem_data_da_virada_nao_exporta(): void
    {
        $this->artisan('app:rollback-export', ['--dir' => $this->dir])->assertFailed();
        $this->artisan('app:rollback-export', ['--since' => 'ontem', '--dir' => $this->dir])->assertFailed();
        $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'agendamentos.csv');
    }
}

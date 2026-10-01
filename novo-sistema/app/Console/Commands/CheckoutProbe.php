<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sonda do TESTE DE CONCORRENCIA do caixa (CheckoutConcurrencyTest): cada
 * processo tenta, ao mesmo tempo que os outros, concluir o mesmo
 * atendimento, tirar do estoque o mesmo produto ou fechar o mesmo caixa,
 * pelos servicos de verdade. Espera um arquivo de "largada" e segura a
 * transacao aberta (--hold) na janela de corrida. So roda em local/testing.
 */
class CheckoutProbe extends Command
{
    protected $signature = 'app:checkout-probe
        {mode : complete | stock | close}
        {id : atendimento, produto ou caixa}
        {actor : usuario que executa}
        {--barrier= : arquivo que libera a largada}
        {--hold=300 : milissegundos segurando a transacao}';

    protected $description = 'Tenta uma operacao de caixa/estoque (so para o teste de concorrencia)';

    public function handle(AttendanceService $attendances, AttendancePricing $pricing, StockLedger $stock, CashRegister $cash): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Somente em local/testing.');

            return self::FAILURE;
        }

        $barreira = (string) $this->option('barrier');
        $limite = microtime(true) + 20;
        while ($barreira !== '' && ! file_exists($barreira) && microtime(true) < $limite) {
            usleep(5000);
        }

        $esperar = fn () => usleep((int) $this->option('hold') * 1000);
        AttendanceService::$beforeFinish = $esperar;
        StockLedger::$afterCheck = $esperar;
        CashRegister::$afterLock = $esperar;

        $id = (int) $this->argument('id');
        $ator = User::query()->findOrFail((int) $this->argument('actor'));

        try {
            $resultado = match ((string) $this->argument('mode')) {
                'complete' => (function () use ($attendances, $pricing, $id, $ator) {
                    $at = Attendance::query()->findOrFail($id);
                    $total = (int) $pricing->breakdown($at)->total?->cents;

                    return $attendances->complete($at, [new PaymentLine(PaymentMethod::Pix, $total)], (string) Str::uuid(), $ator)->id;
                })(),
                'stock' => $stock->issue(Product::query()->findOrFail($id), 1, StockMovementKind::Usage, 'Teste de concorrência', $ator)->id,
                'close' => (function () use ($cash, $id, $ator) {
                    $s = CashSession::query()->findOrFail($id);

                    return $cash->close($s, $cash->expectedCash($s), null, $ator)->id;
                })(),
                default => throw new \InvalidArgumentException('modo desconhecido'),
            };
            $this->line('OK '.$resultado);
        } catch (CheckoutRuleViolation|StockRuleViolation|CashRuleViolation $e) {
            $this->line('RULE '.$e->reason);
        } catch (Throwable $e) {
            $this->line('ERROR '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\CashSessionStatus;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Caixa: abrir, movimentar, fechar, diferenca e historico.
 */
class CashRegisterTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    private function assertRule(string $motivo, \Closure $acao): void
    {
        try {
            $acao();
            $this->fail("deveria recusar: {$motivo}");
        } catch (CashRuleViolation $e) {
            $this->assertSame($motivo, $e->reason);
        }
    }

    public function test_abre_com_responsavel_data_e_valor_inicial(): void
    {
        $s = $this->cash()->open(15000, 'Troco do cofre', $this->recepcao);

        $this->assertSame(CashSessionStatus::Open, $s->status);
        $this->assertSame([15000, 'Troco do cofre', $this->recepcao->id], [$s->opening_float_cents, $s->opening_notes, $s->opened_by_user_id]);
        $this->assertNotNull($s->opened_at);
        $this->assertTrue(AuditLog::query()->where('action', 'cash.opened')->exists());
    }

    public function test_um_caixa_aberto_por_vez(): void
    {
        $this->openCash();

        $this->assertRule('already_open', fn () => $this->cash()->open(0, null, $this->recepcao));
        $this->assertSame(1, CashSession::query()->count());
    }

    public function test_valor_inicial_negativo_e_recusado(): void
    {
        $this->assertRule('invalid_amount', fn () => $this->cash()->open(-1, null, $this->recepcao));
    }

    public function test_suprimento_e_sangria_com_motivo_e_limite(): void
    {
        $s = $this->openCash(10000);

        $this->cash()->supply($s, 5000, 'Reforço de troco', $this->recepcao);
        $this->cash()->withdraw($s, 12000, 'Depósito no banco', $this->recepcao);

        $this->assertSame(3000, $this->cash()->expectedCash($s));
        $this->assertRule('insufficient_cash', fn () => $this->cash()->withdraw($s, 3001, 'Retirada', $this->recepcao));
        $this->assertRule('reason_required', fn () => $this->cash()->supply($s, 100, ' ', $this->recepcao));
        $this->assertRule('invalid_amount', fn () => $this->cash()->supply($s, 0, 'Motivo válido', $this->recepcao));

        $tipos = CashMovement::query()->orderBy('id')->get()->map(fn ($m) => [$m->type, $m->amount_cents, $m->created_by_user_id])->all();
        $this->assertSame([[CashMovementType::Supply, 5000, $this->recepcao->id], [CashMovementType::Withdrawal, -12000, $this->recepcao->id]], $tipos);
    }

    public function test_suprimento_repetido_com_a_mesma_chave_nao_duplica(): void
    {
        $s = $this->openCash();
        $chave = $this->key();

        $a = $this->cash()->supply($s, 5000, 'Reforço de troco', $this->recepcao, $chave);
        $b = $this->cash()->supply($s, 5000, 'Reforço de troco', $this->recepcao, $chave);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CashMovement::query()->count());
    }

    public function test_fechamento_sem_diferenca(): void
    {
        $s = $this->openCash(10000);
        $this->attendances()->complete($this->startedAttendance(), [new PaymentLine(PaymentMethod::Cash, 5000, 200)], $this->key(), $this->recepcao);

        $s = $this->cash()->close($s, 15200, null, $this->recepcao);

        $this->assertSame(CashSessionStatus::Closed, $s->status);
        $this->assertSame([15200, 15200, 0], [$s->expected_cash_cents, $s->counted_cash_cents, $s->difference_cents]);
        $this->assertNull($s->open_marker);
        $this->assertNotNull($s->closed_at);
        $this->assertTrue(AuditLog::query()->where('action', 'cash.closed')->exists());
    }

    public function test_pix_e_cartao_nao_entram_no_dinheiro_esperado(): void
    {
        $s = $this->openCash(10000);
        $this->attendances()->complete($this->startedAttendance(), [
            new PaymentLine(PaymentMethod::Pix, 3000), new PaymentLine(PaymentMethod::Cash, 2000),
        ], $this->key(), $this->recepcao);

        $r = $this->cash()->summary($s);
        $this->assertEquals(['pix' => 3000, 'cash' => 2000], $r['by_method']);
        $this->assertSame(12000, $r['expected_cash']);
    }

    public function test_diferenca_exige_justificativa_e_fica_registrada(): void
    {
        $s = $this->openCash(10000);

        $this->assertRule('justification_required', fn () => $this->cash()->close($s, 9500, null, $this->recepcao));
        $this->assertSame(CashSessionStatus::Open, $s->fresh()?->status, 'nada gravado');

        $s = $this->cash()->close($s, 9500, 'Troco dado a mais', $this->recepcao);
        $this->assertSame([10000, 9500, -500, 'Troco dado a mais'], [$s->expected_cash_cents, $s->counted_cash_cents, $s->difference_cents, $s->closing_notes]);
    }

    public function test_fechamento_duplicado_e_caixa_fechado_nao_movimenta(): void
    {
        $s = $this->openCash();
        $this->cash()->close($s, 10000, null, $this->recepcao);

        $this->assertRule('closed', fn () => $this->cash()->close($s, 10000, null, $this->recepcao));
        $this->assertRule('closed', fn () => $this->cash()->supply($s, 100, 'Reforço', $this->recepcao));
        $this->assertRule('no_open_session', fn () => $this->cash()->lockOpen());
    }

    public function test_caixa_inexistente_nao_recebe_pagamento(): void
    {
        $at = $this->startedAttendance();

        $this->assertRule('no_open_session', fn () => $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao));
    }

    public function test_caixa_fechado_e_razao_sao_historico(): void
    {
        $s = $this->openCash();
        $m = $this->cash()->supply($s, 100, 'Reforço de troco', $this->recepcao);
        $s = $this->cash()->close($s, 10100, null, $this->recepcao);

        foreach ([fn () => $s->update(['counted_cash_cents' => 1]), fn () => $s->delete(), fn () => $m->update(['amount_cents' => 5]), fn () => $m->delete()] as $acao) {
            try {
                $acao();
                $this->fail('histórico não muda');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_depois_de_fechar_abre_outro(): void
    {
        $s = $this->openCash();
        $this->cash()->close($s, 10000, null, $this->recepcao);

        $novo = $this->cash()->open(5000, null, $this->recepcao);

        $this->assertNotSame($s->id, $novo->id);
        $this->assertSame(2, CashSession::query()->count());
    }
}

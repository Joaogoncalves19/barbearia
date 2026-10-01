<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\Payment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Estorno de pagamento e devolucao ao estoque: o passado nao e reescrito.
 */
class CorrectionsTest extends TestCase
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

    public function test_estorno_parcial_e_total_preservam_o_pagamento_original(): void
    {
        $caixa = $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), [new PaymentLine(PaymentMethod::Cash, 5000, 500)], $this->key(), $this->recepcao);
        $pg = $at->payments()->sole();

        $e1 = $this->corrections()->refund($pg, 2000, 'Cliente reclamou do acabamento', $this->recepcao, $this->key());
        $e2 = $this->corrections()->refund($pg, 3500, 'Devolução do restante', $this->recepcao, $this->key());

        $this->assertSame([PaymentKind::Refund, $pg->id, 2000, PaymentMethod::Cash], [$e1->kind, $e1->refunds_payment_id, $e1->amount_cents, $e1->method]);
        $this->assertSame(0, $this->corrections()->refundable($pg));
        $this->assertSame([5000, 500], [$pg->fresh()?->amount_cents, $pg->fresh()?->tip_cents], 'o pagamento original não muda');
        $this->assertSame(AttendanceStatus::Completed, $at->fresh()?->status);
        $this->assertSame(5000, $at->fresh()?->total_cents, 'o atendimento guarda o valor do dia');

        $saidas = CashMovement::query()->where('type', CashMovementType::Refund)->orderBy('id')->pluck('amount_cents')->all();
        $this->assertSame([-2000, -3500], $saidas);
        $this->assertSame(10000, $this->cash()->expectedCash($caixa), '100 + 55 - 20 - 35');

        $this->assertRule('refund_exceeds', fn () => $this->corrections()->refund($pg, 1, 'Mais um', $this->recepcao, $this->key()));
        $this->assertSame(2, $at->events()->where('type', 'refunded')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'payment.refunded')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_estorno_repetido_com_a_mesma_chave_nao_duplica(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);
        $pg = $at->payments()->sole();
        $chave = $this->key();

        $a = $this->corrections()->refund($pg, 1000, 'Ajuste combinado', $this->recepcao, $chave);
        $b = $this->corrections()->refund($pg, 1000, 'Ajuste combinado', $this->recepcao, $chave);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::query()->where('kind', 'refund')->count());
        $this->assertSame(1, CashMovement::query()->where('type', 'refund')->count());
    }

    public function test_estorno_exige_motivo_valor_e_caixa_aberto(): void
    {
        $caixa = $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);
        $pg = $at->payments()->sole();

        $this->assertRule('reason_required', fn () => $this->corrections()->refund($pg, 100, '', $this->recepcao, $this->key()));
        $this->assertRule('invalid_amount', fn () => $this->corrections()->refund($pg, 0, 'Motivo', $this->recepcao, $this->key()));

        $this->cash()->close($caixa, 10000, null, $this->recepcao);
        $this->assertRule('no_open_session', fn () => $this->corrections()->refund($pg, 100, 'Motivo válido', $this->recepcao, $this->key()));
        $this->assertSame(0, Payment::query()->where('kind', 'refund')->count());
    }

    public function test_estorno_de_estorno_nao_existe(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);
        $e = $this->corrections()->refund($at->payments()->sole(), 1000, 'Ajuste', $this->recepcao, $this->key());

        $this->assertRule('not_refundable', fn () => $this->corrections()->refund($e, 100, 'Estorno do estorno', $this->recepcao, $this->key()));
    }

    public function test_pagamento_e_imutavel(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->expectException(DomainRuleViolation::class);
        $at->payments()->sole()->update(['amount_cents' => 1]);
    }

    public function test_devolucao_ao_estoque_de_produto_vendido(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 2, $this->recepcao);
        $at = $this->attendances()->complete($at, $this->pay(12000), $this->key(), $this->recepcao);
        $venda = StockMovement::query()->where('attendance_id', $at->id)->sole();

        $r = $this->corrections()->returnToStock($venda, 'Cliente devolveu fechado', $this->recepcao, $this->key());

        $this->assertSame([StockMovementKind::Reversal, 2, $venda->id, $at->id], [$r->kind, $r->quantity, $r->reverses_movement_id, $r->attendance_id]);
        $this->assertSame(10, $this->stock()->balance($this->pomada));
        $this->assertSame(1, $at->events()->where('type', 'stock_returned')->count());
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Gorjeta (do profissional, separada da comissao) e o efeito do estorno de
 * pagamento nas duas (decisao do dono D-35): a parte da gorjeta vira gorjeta
 * negativa; o resto reduz a comissao na mesma proporcao. Sempre lancamento
 * novo; o original nunca muda.
 */
class TipsAndRefundsTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    private function commissionOf(int $attendanceId): int
    {
        return (int) CommissionEntry::query()->where('attendance_id', $attendanceId)->sum('amount_cents');
    }

    private function assertCash(string $motivo, \Closure $acao): void
    {
        try {
            $acao();
            $this->fail("deveria recusar: {$motivo}");
        } catch (CashRuleViolation $e) {
            $this->assertSame($motivo, $e->reason);
        }
    }

    public function test_uma_gorjeta_por_pagamento_separada_da_comissao(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->completedAttendance([
            new PaymentLine(PaymentMethod::Pix, 3000, 500),
            new PaymentLine(PaymentMethod::Cash, 2000, 300),
        ]);

        $gorjetas = TipEntry::query()->where('attendance_id', $at->id)->orderBy('id')->get();
        $pagamentos = Payment::query()->where('attendance_id', $at->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame([500, 300], $gorjetas->pluck('amount_cents')->all());
        $this->assertSame($pagamentos, $gorjetas->pluck('payment_id')->all(), 'cada gorjeta aponta o pagamento de origem');
        $this->assertSame([LedgerEntryKind::Earned, $this->joao->id], [$gorjetas[0]->kind, $gorjetas[0]->professional_id]);

        $this->assertSame(['commission' => 2000, 'tips' => 800, 'advances' => 0, 'net' => 2800], $this->ledger()->open($this->joao));
        $this->assertSame(2000, $this->commissionOf($at->id), 'gorjeta não entra na base da comissão');
    }

    public function test_gorjeta_e_imutavel(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $g = TipEntry::query()->where('attendance_id', $at->id)->sole();

        $this->expectException(DomainRuleViolation::class);
        $g->update(['amount_cents' => 1]);
    }

    public function test_estorno_parcial_reduz_a_comissao_na_mesma_proporcao(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->completedAttendance();
        $pg = $at->payments()->sole();

        $e1 = $this->corrections()->refund($pg, 2500, 'Metade do serviço refeito', $this->recepcao, $this->key());
        $reversao = CommissionEntry::query()->where('kind', LedgerEntryKind::Refund)->sole();
        $this->assertSame([-1000, $e1->id, 2500], [$reversao->amount_cents, $reversao->payment_id, $reversao->base_cents]);
        $this->assertSame(1000, $this->commissionOf($at->id));

        $this->corrections()->refund($pg, 2500, 'Devolução do restante', $this->recepcao, $this->key());
        $this->assertSame(0, $this->commissionOf($at->id), 'estorno total zera a comissão');
        $this->assertSame(2000, (int) CommissionEntry::query()->where('kind', LedgerEntryKind::Earned)->sum('amount_cents'), 'o lançamento original não muda');
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_varios_estornos_parciais_somam_o_mesmo_que_um_so(): void
    {
        $this->percent(3750, $this->joao); // 18,75 sobre 50,00

        $a = $this->completedAttendance();
        $pg = $a->payments()->sole();
        foreach ([1000, 1000, 1000] as $v) {
            $this->corrections()->refund($pg, $v, 'Parcial', $this->recepcao, $this->key());
        }
        $parcelas = CommissionEntry::query()->where('attendance_id', $a->id)->where('kind', LedgerEntryKind::Refund)->orderBy('id')->pluck('amount_cents')->all();
        $this->assertSame([-375, -375, -375], $parcelas);

        $this->clockAt('09:02');
        $b = $this->finish($this->attendances()->start($this->walkIn(), $this->recepcao));
        $this->corrections()->refund($b->payments()->sole(), 3000, 'De uma vez', $this->recepcao, $this->key());

        $this->assertSame(array_sum($parcelas), (int) CommissionEntry::query()->where('attendance_id', $b->id)->where('kind', LedgerEntryKind::Refund)->sum('amount_cents'));
    }

    public function test_parte_da_gorjeta_estornada_vira_gorjeta_negativa(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $pg = $at->payments()->sole();

        $e = $this->corrections()->refund($pg, 800, 'Cliente pediu a gorjeta de volta e 3,00', $this->recepcao, $this->key(), tipCents: 500);

        $this->assertSame([PaymentKind::Refund, 300, 500], [$e->kind, $e->amount_cents, $e->tip_cents]);
        $neg = TipEntry::query()->where('kind', LedgerEntryKind::Refund)->sole();
        $this->assertSame([-500, $e->id, $this->joao->id], [$neg->amount_cents, $neg->payment_id, $neg->professional_id]);
        $this->assertSame(-120, (int) CommissionEntry::query()->where('kind', LedgerEntryKind::Refund)->sum('amount_cents'), '3,00 de 50,00 = 6% da comissão');
        $this->assertSame(['commission' => 1880, 'tips' => 0, 'advances' => 0, 'net' => 1880], $this->ledger()->open($this->joao));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_estorno_nao_passa_da_gorjeta_nem_do_valor(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $pg = $at->payments()->sole();

        $this->assertCash('refund_tip_exceeds', fn () => $this->corrections()->refund($pg, 600, 'Gorjeta a mais', $this->recepcao, $this->key(), tipCents: 600));
        $this->assertCash('refund_amount_exceeds', fn () => $this->corrections()->refund($pg, 5500, 'Tudo como serviço', $this->recepcao, $this->key(), tipCents: 0));
        $this->assertCash('invalid_amount', fn () => $this->corrections()->refund($pg, 100, 'Gorjeta maior que o estorno', $this->recepcao, $this->key(), tipCents: 200));

        $this->corrections()->refund($pg, 5500, 'Tudo', $this->recepcao, $this->key(), tipCents: 500);
        $this->assertSame([0, 0], [$this->corrections()->refundable($pg), $this->corrections()->refundableTip($pg)]);
        $this->assertSame(0, $this->ledger()->open($this->joao)['net']);
    }

    public function test_estorno_depois_do_repasse_entra_no_proximo(): void
    {
        $this->percent(4000, $this->joao);
        $a = $this->completedAttendance();
        $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());

        $this->corrections()->refund($a->payments()->sole(), 1000, 'Reclamação', $this->recepcao, $this->key());
        $this->assertSame(-400, $this->ledger()->open($this->joao)['net'], 'em aberto e negativo: desconta no próximo repasse');

        $this->clockAt('09:02');
        $this->finish($this->attendances()->start($this->walkIn(), $this->recepcao));
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        $this->assertSame([1600, 2000 - 400], [$p->amount_cents, $p->commission_cents]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }
}

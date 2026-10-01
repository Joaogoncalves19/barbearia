<?php

namespace Tests\Feature\Finance;

use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\AdvanceKind;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Repasse (comissao + gorjeta - vales), vales e correcoes manuais
 * (repasses.md). Nada e editado: estorno e ajuste sao lancamentos novos;
 * o que entrou num repasse nunca entra em outro.
 */
class PayoutsAndAdvancesTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->percent(4000, $this->joao);
    }

    private function assertRule(string $motivo, \Closure $acao): void
    {
        try {
            $acao();
            $this->fail("deveria recusar: {$motivo}");
        } catch (CommissionRuleViolation|CashRuleViolation $e) {
            $this->assertSame($motivo, $e->reason);
        }
    }

    // --- Repasse ---------------------------------------------------------------------------------

    public function test_repasse_fecha_comissao_mais_gorjeta_menos_vales(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 600)]);
        $vale = $this->advances()->issue($this->joao, 500, PaymentMethod::Pix, 'Adiantamento da semana', $this->financeiro, $this->key());

        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, 'Pago por Pix', $this->financeiro, $this->key());

        $this->assertSame([2000, 600, 500, 2100], [$p->commission_cents, $p->tip_cents, $p->advances_cents, $p->amount_cents]);
        $this->assertSame([PaymentMethod::Pix, null], [$p->method, $p->cash_session_id], 'Pix não mexe no caixa');
        $this->assertSame($p->id, CommissionEntry::query()->where('attendance_id', $at->id)->sole()->commission_payout_id);
        $this->assertSame($p->id, TipEntry::query()->sole()->commission_payout_id);
        $this->assertSame($p->id, $vale->fresh()?->commission_payout_id);
        $this->assertCount(1, $p->snapshot['comissoes'] ?? []);
        $this->assertSame(['commission' => 0, 'tips' => 0, 'advances' => 0, 'net' => 0], $this->ledger()->open($this->joao));
        $this->assertSame(1, AuditLog::query()->where('action', 'payout.created')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        $this->assertRule('nothing_to_pay', fn () => $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key()));
    }

    public function test_repasse_em_dinheiro_sai_do_caixa_e_respeita_o_dinheiro_disponivel(): void
    {
        $this->completedAttendance(); // Pix: a gaveta continua com os 100,00 iniciais
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $this->key());

        $saida = CashMovement::query()->where('type', CashMovementType::Payout)->sole();
        $this->assertSame([-2000, $p->id], [$saida->amount_cents, $saida->commission_payout_id]);
        $this->assertSame(8000, $this->cash()->expectedCash($this->cash()->current()));

        // Sem dinheiro na gaveta para pagar: recusado, nada gravado.
        $this->cash()->withdraw($this->cash()->current(), 8000, 'Depósito no banco', $this->recepcao);
        $this->clockAt('09:02');
        $this->finish($this->attendances()->start($this->walkIn(), $this->recepcao));
        $this->assertRule('insufficient_cash_for_professional', fn () => $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $this->key()));
        $this->assertSame(1, CommissionPayout::query()->count());
        $this->assertSame(2000, $this->ledger()->open($this->joao)['net'], 'continua em aberto');
    }

    public function test_repasse_ate_um_instante_deixa_o_resto_em_aberto(): void
    {
        $this->completedAttendance();
        $corte = now()->addMinute();
        $this->travel(2)->hours();
        $this->clockAt('12:02');
        $this->finish($this->attendances()->start($this->walkIn(), $this->recepcao));

        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key(), $corte);

        $this->assertSame(2000, $p->amount_cents);
        $this->assertSame(2000, $this->ledger()->open($this->joao)['commission'], 'o atendimento depois do corte fica para o próximo');
    }

    public function test_mesma_chave_nao_duplica_repasse(): void
    {
        $this->completedAttendance();
        $chave = $this->key();

        $a = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $chave);
        $b = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $chave);

        $this->assertSame($a->id, $b->id);
        $this->assertSame([1, 1], [CommissionPayout::query()->count(), CashMovement::query()->where('type', 'payout')->count()]);
    }

    public function test_saldo_negativo_nao_vira_repasse(): void
    {
        $this->completedAttendance();
        $this->advances()->issue($this->joao, 3000, PaymentMethod::Pix, 'Adiantamento grande', $this->financeiro, $this->key());

        $this->assertRule('negative_balance', fn () => $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key()));
        $this->assertSame(0, CommissionPayout::query()->count());
        $this->assertRule('invalid_method', fn () => $this->payouts()->pay($this->joao, PaymentMethod::CreditCard, null, $this->financeiro, $this->key()));
    }

    public function test_repasse_zerado_por_vale_fecha_sem_dinheiro(): void
    {
        $this->completedAttendance();
        $this->advances()->issue($this->joao, 2000, PaymentMethod::Pix, 'Adiantamento', $this->financeiro, $this->key());

        $p = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $this->key());

        $this->assertSame([0, null], [$p->amount_cents, $p->cash_session_id]);
        $this->assertSame(0, CashMovement::query()->where('type', 'payout')->count());
    }

    public function test_estorno_de_repasse_devolve_ao_saldo_e_ao_caixa(): void
    {
        $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $this->key());

        $this->payouts()->reverse($p, 'Valor pago à pessoa errada', $this->dono, $this->key());

        $p->refresh();
        $this->assertTrue($p->isReversed());
        $this->assertSame(['commission' => 2000, 'tips' => 500, 'advances' => 0, 'net' => 2500], $this->ledger()->open($this->joao));
        $this->assertSame([-2500, 2500], CashMovement::query()->whereIn('type', ['payout', 'payout_reversal'])->orderBy('id')->pluck('amount_cents')->all());
        $this->assertCount(1, $p->snapshot['comissoes'] ?? [], 'o repasse continua dizendo o que tinha');
        $this->assertSame(1, AuditLog::query()->where('action', 'payout.reversed')->count());

        $this->assertRule('already_reversed', fn () => $this->payouts()->reverse($p, 'De novo', $this->dono, $this->key()));
        $this->assertRule('reason_required', fn () => $this->payouts()->reverse($p, ' ', $this->dono, $this->key()));

        $novo = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        $this->assertSame(2500, $novo->amount_cents, 'os lançamentos podem entrar num novo repasse');
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_repasse_e_imutavel_e_o_do_sistema_antigo_nao_e_estornado(): void
    {
        $this->completedAttendance();
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        try {
            $p->update(['amount_cents' => 1]);
            $this->fail('repasse não pode ser editado');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }

        $antigo = CommissionPayout::query()->create(['professional_id' => $this->joao->id, 'amount_cents' => 30000, 'tip_cents' => 1000, 'reference_month' => '2026-08']);
        $this->assertTrue($antigo->isLegacy());
        $this->assertRule('legacy_payout', fn () => $this->payouts()->reverse($antigo, 'Teste', $this->dono, $this->key()));
    }

    // --- Vales -----------------------------------------------------------------------------------

    public function test_vale_em_dinheiro_sai_do_caixa_e_estorno_devolve_uma_vez(): void
    {
        $vale = $this->advances()->issue($this->joao, 3000, PaymentMethod::Cash, 'Adiantamento', $this->financeiro, $this->key());

        $this->assertSame([AdvanceKind::Advance, 3000, $this->cash()->current()?->id], [$vale->kind, $vale->amount_cents, $vale->cash_session_id]);
        $this->assertSame(7000, $this->cash()->expectedCash($this->cash()->current()));
        $this->assertSame(3000, $this->ledger()->open($this->joao)['advances']);

        $estorno = $this->advances()->reverse($vale, 'Lançado em dobro', $this->dono, $this->key());
        $this->assertSame([AdvanceKind::Reversal, -3000, $vale->id], [$estorno->kind, $estorno->amount_cents, $estorno->reverses_advance_id]);
        $this->assertSame(10000, $this->cash()->expectedCash($this->cash()->current()), 'o dinheiro volta');
        $this->assertSame(0, $this->ledger()->open($this->joao)['advances']);

        $this->assertRule('already_reversed', fn () => $this->advances()->reverse($vale, 'De novo', $this->dono, $this->key()));
        $this->assertRule('not_reversible', fn () => $this->advances()->reverse($estorno, 'Estorno do estorno', $this->dono, $this->key()));
        $this->assertSame(['advance.issued' => 1, 'advance.reversed' => 1], [
            'advance.issued' => AuditLog::query()->where('action', 'advance.issued')->count(),
            'advance.reversed' => AuditLog::query()->where('action', 'advance.reversed')->count(),
        ]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_vale_valida_valor_motivo_e_e_imutavel(): void
    {
        $this->assertRule('invalid_amount', fn () => $this->advances()->issue($this->joao, 0, PaymentMethod::Pix, 'Zero', $this->financeiro, $this->key()));
        $this->assertRule('reason_required', fn () => $this->advances()->issue($this->joao, 100, PaymentMethod::Pix, ' ', $this->financeiro, $this->key()));
        $this->assertRule('invalid_method', fn () => $this->advances()->issue($this->joao, 100, PaymentMethod::CreditCard, 'Cartão', $this->financeiro, $this->key()));

        $chave = $this->key();
        $a = $this->advances()->issue($this->joao, 100, PaymentMethod::Pix, 'Vale', $this->financeiro, $chave);
        $this->assertSame($a->id, $this->advances()->issue($this->joao, 100, PaymentMethod::Pix, 'Vale', $this->financeiro, $chave)->id, 'mesma chave, um vale');

        $this->expectException(DomainRuleViolation::class);
        $a->update(['amount_cents' => 1]);
    }

    public function test_vale_do_sistema_antigo_e_historico(): void
    {
        $antigo = Advance::query()->create(['professional_id' => $this->joao->id, 'amount_cents' => 5000, 'reference_month' => '2026-08', 'is_legacy' => true]);

        $this->assertSame(0, $this->ledger()->open($this->joao)['advances'], 'já foi abatido no sistema antigo');
        $this->assertRule('not_reversible', fn () => $this->advances()->reverse($antigo, 'Teste', $this->dono, $this->key()));
    }

    // --- Correcao manual -------------------------------------------------------------------------

    public function test_ajuste_manual_de_comissao_e_de_gorjeta(): void
    {
        $at = $this->completedAttendance();
        $chave = $this->key();

        $a = $this->ledger()->adjust($this->joao, 'commission', -500, 'Retrabalho no corte', $at, $this->financeiro, $chave);
        $this->assertSame($a->id, $this->ledger()->adjust($this->joao, 'commission', -500, 'Retrabalho no corte', $at, $this->financeiro, $chave)->id, 'mesma chave, um ajuste');
        $g = $this->ledger()->adjust($this->joao, 'tip', 300, 'Gorjeta em dinheiro entregue no balcão', null, $this->financeiro, $this->key());

        $this->assertSame([LedgerEntryKind::Adjustment, -500, $this->financeiro->id], [$a->kind, $a->amount_cents, $a->created_by_user_id]);
        $this->assertInstanceOf(TipEntry::class, $g);
        $this->assertSame(['commission' => 1500, 'tips' => 300, 'advances' => 0, 'net' => 1800], $this->ledger()->open($this->joao));
        $this->assertSame([1, 1], [AuditLog::query()->where('action', 'commission.adjusted')->count(), AuditLog::query()->where('action', 'tip.adjusted')->count()]);

        $outro = Professional::factory()->create();
        $this->assertRule('professional_mismatch', fn () => $this->ledger()->adjust($outro, 'commission', 100, 'Errado', $at, $this->financeiro, $this->key()));
        $this->assertRule('reason_required', fn () => $this->ledger()->adjust($this->joao, 'commission', 100, '', null, $this->financeiro, $this->key()));
        $this->assertRule('invalid_amount', fn () => $this->ledger()->adjust($this->joao, 'commission', 0, 'Zero', null, $this->financeiro, $this->key()));
    }

    // --- Verificador de integridade ---------------------------------------------------------------

    public function test_verificador_acusa_valor_gravado_por_fora_das_regras(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        // Gravações diretas no banco (fora dos serviços), como faria um script errado.
        DB::table('commission_entries')->where('attendance_id', $at->id)->delete();
        DB::table('tip_entries')->delete();
        DB::table('commission_entries')->insert(['professional_id' => $this->joao->id, 'attendance_id' => $at->id, 'kind' => 'refund', 'base_cents' => 0, 'amount_cents' => -1]);
        DB::table('commission_payouts')->where('id', $p->id)->update(['commission_cents' => 9999]);

        $v = app(IntegrityChecker::class)->violations();
        foreach (['R34_comissao_por_item', 'R35_gorjeta_por_pagamento', 'R36_estorno_de_comissao', 'R37_repasse_fecha'] as $regra) {
            $this->assertArrayHasKey($regra, $v, $regra);
        }
    }

    // --- Integracao completa ---------------------------------------------------------------------

    /**
     * atendimento -> pagamento -> comissao -> gorjeta -> valor devido -> repasse,
     * com desconto, produto, pagamento dividido, vale e estorno.
     */
    public function test_integracao_atendimento_pagamento_comissao_gorjeta_devido_repasse(): void
    {
        $this->percent(1000, $this->joao, null, CommissionTarget::Product);

        // Atendimento: corte 50,00 + barba 30,00 + pomada 35,00; 10% de desconto nos serviços.
        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Cliente fiel', $this->dono);

        // Pagamento dividido com gorjeta nas duas formas: total 107,00.
        $at = $this->finish($at, [new PaymentLine(PaymentMethod::Cash, 7000, 500), new PaymentLine(PaymentMethod::Pix, 3700, 200)]);
        $this->assertSame(10700, $at->total_cents);

        // Comissão: 40% de 45,00 + 40% de 27,00 + 10% de 35,00 = 18,00 + 10,80 + 3,50.
        $this->assertSame([1800, 1080, 350], $this->earned($at));
        $this->assertSame([500, 200], TipEntry::query()->orderBy('id')->pluck('amount_cents')->all());

        // Vale e estorno parcial (10,70 do Pix, nada de gorjeta): comissão -10% = -3,23.
        $this->advances()->issue($this->joao, 1000, PaymentMethod::Cash, 'Vale da semana', $this->financeiro, $this->key());
        $pix = $at->payments()->where('method', 'pix')->sole();
        $this->corrections()->refund($pix, 1070, 'Produto com defeito', $this->dono, $this->key());

        $devido = $this->ledger()->open($this->joao);
        $this->assertSame(['commission' => 3230 - 323, 'tips' => 700, 'advances' => 1000, 'net' => 2907 + 700 - 1000], $devido);

        // Repasse em dinheiro: sai do caixa o líquido devido.
        $antes = $this->cash()->expectedCash($this->cash()->current());
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Cash, null, $this->financeiro, $this->key());
        $this->assertSame($devido['net'], $p->amount_cents);
        $this->assertSame($antes - $devido['net'], $this->cash()->expectedCash($this->cash()->current()));
        $this->assertSame(0, $this->ledger()->open($this->joao)['net']);
        $this->assertSame(0, DB::table('commission_entries')->whereNull('commission_payout_id')->count() + DB::table('tip_entries')->whereNull('commission_payout_id')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }
}

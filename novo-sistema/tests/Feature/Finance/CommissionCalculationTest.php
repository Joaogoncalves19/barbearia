<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\LedgerEntryKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Calculo da comissao (comissoes.md): regras e precedencia, desconto
 * rateado, produto, arredondamento, regra versionada e o principio de que
 * nada ja calculado muda depois. Tudo na transacao da conclusao.
 */
class CommissionCalculationTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    private function assertRule(string $motivo, \Closure $acao): void
    {
        try {
            $acao();
            $this->fail("deveria recusar: {$motivo}");
        } catch (CommissionRuleViolation $e) {
            $this->assertSame($motivo, $e->reason);
        }
    }

    // --- Calculo ---------------------------------------------------------------------------------

    public function test_percentual_do_profissional_sobre_o_servico(): void
    {
        $regra = $this->percent(4000, $this->joao);

        $at = $this->completedAttendance();

        $e = CommissionEntry::query()->where('attendance_id', $at->id)->sole();
        $this->assertSame([LedgerEntryKind::Earned, $this->joao->id, 5000, 4000, 2000, $regra->id, 'Corte', 1],
            [$e->kind, $e->professional_id, $e->base_cents, $e->rate_bp, $e->amount_cents, $e->commission_rule_id, $e->item_name, $e->quantity]);
        $this->assertSame(['40%', 'service'], [$e->rule['descricao'] ?? null, $e->rule['alvo'] ?? null], 'regra fotografada no lançamento');
        $this->assertNull($e->commission_payout_id, 'em aberto até o repasse');
        $this->assertEquals($at->completed_at, $e->occurred_at);
    }

    public function test_precedencia_profissional_e_servico_servico_profissional_padrao(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->percent(3000);                                // padrão da barbearia
        $this->percent(4000, $this->joao);                   // João, todos os serviços
        $this->percent(2000, null, $this->barba);            // barba, todos
        $this->percent(5000, $this->joao, $this->barba);     // João + barba

        $this->assertSame(4000, $this->rules()->resolve(CommissionTarget::Service, $this->joao->id, $this->corte->id)?->rate_bp, 'profissional vence o padrão');
        $this->assertSame(5000, $this->rules()->resolve(CommissionTarget::Service, $this->joao->id, $this->barba->id)?->rate_bp, 'profissional + serviço vence tudo');
        $this->assertSame(2000, $this->rules()->resolve(CommissionTarget::Service, $maria->id, $this->barba->id)?->rate_bp, 'serviço vence o padrão e o profissional');
        $this->assertSame(3000, $this->rules()->resolve(CommissionTarget::Service, $maria->id, $this->corte->id)?->rate_bp, 'só o padrão');

        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $at = $this->finish($at);

        $this->assertSame([2000, 1500], $this->earned($at), 'corte 40% de 50,00; barba 50% de 30,00');
    }

    public function test_valor_fixo_por_servico(): void
    {
        $this->percent(4000, $this->joao);
        $this->fixed(1200, null, $this->corte);

        $at = $this->startedAttendance();
        $this->attendances()->applyDiscount($at, Discount::percent(5000), 'Promoção da casa', $this->dono);
        $at = $this->finish($at);

        $e = CommissionEntry::query()->where('attendance_id', $at->id)->sole();
        $this->assertSame([2500, null, 1200, 'R$ 12,00 por unidade'], [$e->base_cents, $e->rate_bp, $e->amount_cents, $e->rule['descricao'] ?? null], 'fixo não depende do desconto');
    }

    public function test_servico_sem_comissao_vence_a_regra_do_profissional(): void
    {
        $this->percent(4000, $this->joao);
        $this->noCommission(null, $this->barba);

        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $at = $this->finish($at);

        $this->assertSame([2000, 0], $this->earned($at));
        $barba = CommissionEntry::query()->where('attendance_id', $at->id)->where('item_name', 'Barba')->sole();
        $this->assertSame('Sem comissão', $barba->rule['descricao'] ?? null, 'o lançamento existe e diz por que deu zero');
    }

    public function test_profissional_sem_comissao(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 700)]);

        $e = CommissionEntry::query()->where('attendance_id', $at->id)->sole();
        $this->assertSame([0, null], [$e->amount_cents, $e->commission_rule_id]);
        $this->assertSame('Sem regra de comissão para este item', $e->rule['descricao'] ?? null);
        $this->assertSame(700, (int) TipEntry::query()->where('attendance_id', $at->id)->sum('amount_cents'), 'gorjeta não depende de regra de comissão');

        // "Sem comissão" explícito para o profissional também vence o padrão da barbearia.
        $this->percent(3000);
        $this->noCommission($this->joao);
        $this->assertSame(CommissionRuleType::None, $this->rules()->resolve(CommissionTarget::Service, $this->joao->id, $this->corte->id)?->type);
    }

    public function test_desconto_rateado_pelo_maior_resto_entre_os_servicos(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::fixed(1001), 'Cortesia', $this->dono);
        $at = $this->finish($at);

        // 10,01 sobre 50,00 + 30,00: 6,25625 e 3,75375 -> 6,26 e 3,75 (o centavo vai para a maior fração).
        $bases = CommissionEntry::query()->where('attendance_id', $at->id)->orderBy('attendance_item_id')->pluck('base_cents')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([4374, 2625], $bases);
        $this->assertSame(1001, 8000 - array_sum($bases), 'a soma das partes é exatamente o desconto');
        $this->assertSame([1750, 1050], $this->earned($at), '40% de 43,74 = 17,496 -> 17,50; 40% de 26,25 = 10,50');
    }

    public function test_produto_com_percentual_proprio_e_sem_desconto(): void
    {
        $this->percent(4000, $this->joao);
        $this->percent(1000, $this->joao, null, CommissionTarget::Product);

        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 2, $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Cliente fiel', $this->dono);
        $at = $this->finish($at);

        $pomada = CommissionEntry::query()->where('attendance_id', $at->id)->where('item_name', 'Pomada modeladora')->sole();
        $this->assertSame([7000, 700, 1000, 'product'], [$pomada->base_cents, $pomada->amount_cents, $pomada->rate_bp, $pomada->rule['alvo'] ?? null], 'desconto não incide em produto');
        $corte = CommissionEntry::query()->where('attendance_id', $at->id)->where('item_name', 'Corte')->sole();
        $this->assertSame([4500, 1800], [$corte->base_cents, $corte->amount_cents]);
    }

    public function test_produto_sem_regra_nao_tem_comissao(): void
    {
        $this->percent(4000, $this->joao); // só serviços

        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $at = $this->finish($at);

        $this->assertSame(0, CommissionEntry::query()->where('attendance_id', $at->id)->where('item_name', 'Pomada modeladora')->sole()->amount_cents);
    }

    public function test_arredondamento_meio_centavo_para_cima(): void
    {
        $quebrado = Service::factory()->create(['name' => 'Pezinho', 'price_cents' => 3333, 'duration_minutes' => 15]);
        $this->joao->services()->attach($quebrado->id);
        $this->percent(3750, $this->joao);

        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $quebrado, $this->recepcao);
        $at = $this->finish($at);

        $this->assertSame([1875, 1250], $this->earned($at), '37,5% de 33,33 = 12,49875 -> 12,50');
    }

    // --- Historico preservado ---------------------------------------------------------------------

    public function test_mudar_regra_preco_e_nome_depois_nao_altera_a_comissao_calculada(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->completedAttendance();
        $antes = CommissionEntry::query()->where('attendance_id', $at->id)->sole()->toArray();

        $this->percent(6000, $this->joao);
        $this->corte->update(['price_cents' => 9000]);
        $this->joao->update(['display_name' => 'João Renomeado']);

        $depois = CommissionEntry::query()->where('attendance_id', $at->id)->sole();
        $this->assertSame($antes, $depois->toArray(), 'nada foi recalculado');
        $this->assertSame(['open' => 2000], ['open' => $this->ledger()->open($this->joao)['commission']]);

        // Atendimento novo usa a regra nova (e o preço novo).
        $this->clockAt('09:02');
        $novo = $this->finish($this->attendances()->start($this->walkIn(), $this->recepcao));
        $this->assertSame([5400], $this->earned($novo), '60% de 90,00');
    }

    public function test_regra_versionada_nunca_editada(): void
    {
        $v1 = $this->percent(4000, $this->joao);
        $this->assertRule('no_change', fn () => $this->percent(4000, $this->joao));

        $v2 = $this->percent(4500, $this->joao);
        $v1->refresh();
        $this->assertNotNull($v1->ends_at);
        $this->assertNull($v1->current_scope);
        $this->assertSame([$v2->id], CommissionRule::query()->whereNotNull('current_scope')->pluck('id')->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'commission.rule_set')->count());

        try {
            $v2->update(['rate_bp' => 9000]);
            $this->fail('regra não pode ser editada');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        try {
            $v1->update(['ends_at' => now()->addDay()]);
            $this->fail('regra encerrada não muda');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }

        $this->rules()->clear(CommissionTarget::Service, $this->joao, null, $this->dono);
        $this->assertNull($this->rules()->resolve(CommissionTarget::Service, $this->joao->id, $this->corte->id), 'sem regra: sem comissão');
        $this->assertRule('no_rule', fn () => $this->rules()->clear(CommissionTarget::Service, $this->joao, null, $this->dono));
        $this->assertSame(1, AuditLog::query()->where('action', 'commission.rule_cleared')->count());
    }

    public function test_regra_invalida_e_recusada(): void
    {
        $this->assertRule('invalid_rule', fn () => $this->percent(10001, $this->joao));
        $this->assertRule('invalid_rule', fn () => $this->percent(-1, $this->joao));
        $this->assertRule('invalid_rule', fn () => $this->rules()->set(CommissionTarget::Product, $this->joao, null, CommissionRuleType::Fixed, null, 500, null, $this->dono));
        $this->assertRule('invalid_rule', fn () => $this->fixed(-5, $this->joao));
        $this->assertSame(0, CommissionRule::query()->count());
    }

    public function test_uma_regra_em_vigor_por_escopo_no_banco(): void
    {
        $r = $this->percent(4000, $this->joao);

        $this->expectException(QueryException::class);
        DB::table('commission_rules')->insert([
            'target' => 'service', 'professional_id' => $this->joao->id, 'type' => 'percent', 'rate_bp' => 1,
            'scope_key' => $r->scope_key, 'current_scope' => $r->scope_key, 'starts_at' => now(),
        ]);
    }

    // --- Transacao e idempotencia ----------------------------------------------------------------

    public function test_falha_ao_registrar_a_comissao_desfaz_a_conclusao_inteira(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        // Lançamento "fantasma" para o item: a gravação da comissão vai falhar (índice único).
        $item = $at->items()->where('name', 'Corte')->sole();
        DB::table('commission_entries')->insert(['professional_id' => $this->joao->id, 'attendance_item_id' => $item->id, 'kind' => 'earned', 'base_cents' => 0, 'amount_cents' => 0]);

        try {
            $this->attendances()->complete($at, [new PaymentLine(PaymentMethod::Cash, 8500, 500)], $this->key(), $this->recepcao);
            $this->fail('a conclusão deveria falhar');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()?->status);
        $this->assertSame([0, 0, 0], [Payment::query()->count(), CashMovement::query()->where('type', 'payment')->count(), TipEntry::query()->count()], 'nada gravado pela metade');
        $this->assertSame(10, $this->stock()->balance($this->pomada), 'estoque intacto');
    }

    public function test_concluir_de_novo_com_a_mesma_chave_nao_duplica_comissao_nem_gorjeta(): void
    {
        $this->percent(4000, $this->joao);
        $at = $this->startedAttendance();
        $chave = $this->key();
        $linhas = [new PaymentLine(PaymentMethod::Pix, 5000, 300)];

        $this->attendances()->complete($at, $linhas, $chave, $this->recepcao);
        $this->attendances()->complete($at, $linhas, $chave, $this->recepcao);

        $this->assertSame([1, 1], [CommissionEntry::query()->count(), TipEntry::query()->count()]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_comissao_vai_para_quem_efetivamente_atendeu(): void
    {
        $pedro = Professional::factory()->create(['display_name' => 'Pedro']);
        $pedro->services()->attach($this->corte->id);
        $this->percent(4000, $this->joao);
        $this->percent(3000, $pedro);

        $at = $this->attendances()->changeProfessional($this->startedAttendance(), $pedro, $this->recepcao);
        $at = $this->finish($at, [new PaymentLine(PaymentMethod::Pix, 5000, 400)]);

        $this->assertSame([$pedro->id], CommissionEntry::query()->pluck('professional_id')->all());
        $this->assertSame([1500], $this->earned($at));
        $this->assertSame([$pedro->id], TipEntry::query()->pluck('professional_id')->all());
        $this->assertSame(0, $this->ledger()->open($this->joao)['net']);
    }
}

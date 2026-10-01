<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Estoque como razao: entrada, saida, perda, ajuste, estorno, saldo.
 */
class StockLedgerTest extends TestCase
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
        } catch (StockRuleViolation $e) {
            $this->assertSame($motivo, $e->reason);
        }
    }

    public function test_entrada_saida_perda_e_saldo_derivado(): void
    {
        $this->stock()->receive($this->pomada, 5, 1400, 'Compra fornecedor', $this->recepcao);
        $this->stock()->issue($this->pomada, 2, StockMovementKind::Usage, 'Uso no salão', $this->recepcao);
        $m = $this->stock()->issue($this->pomada, 1, StockMovementKind::Loss, 'Frasco quebrado', $this->recepcao);

        $this->assertSame(12, $this->stock()->balance($this->pomada));
        $this->assertSame(12, $m->balance_after);
        $this->assertSame([-1, StockMovementKind::Loss, 'Frasco quebrado', $this->recepcao->id], [$m->quantity, $m->kind, $m->reason, $m->created_by_user_id]);
        $this->assertSame((int) StockMovement::query()->where('product_id', $this->pomada->id)->sum('quantity'), $this->stock()->balance($this->pomada));
    }

    public function test_saldo_nunca_fica_negativo(): void
    {
        $this->assertRule('insufficient', fn () => $this->stock()->issue($this->pomada, 11, StockMovementKind::Loss, 'Perda grande', $this->recepcao));
        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }

    public function test_quantidade_e_motivo_invalidos(): void
    {
        $this->assertRule('invalid_quantity', fn () => $this->stock()->receive($this->pomada, 0, null, null, $this->recepcao));
        $this->assertRule('invalid_quantity', fn () => $this->stock()->issue($this->pomada, -2, StockMovementKind::Loss, 'Perda', $this->recepcao));
        $this->assertRule('reason_required', fn () => $this->stock()->issue($this->pomada, 1, StockMovementKind::Loss, '', $this->recepcao));
        $this->assertRule('invalid_quantity', fn () => $this->stock()->adjustTo($this->pomada, -1, 'Inventário', $this->recepcao));
    }

    public function test_ajuste_de_inventario_lanca_a_diferenca(): void
    {
        $m = $this->stock()->adjustTo($this->pomada, 8, 'Inventário mensal', $this->recepcao);

        $this->assertSame([-2, 8, StockMovementKind::Adjustment], [$m->quantity, $m->balance_after, $m->kind]);
        $this->assertSame(8, $this->stock()->balance($this->pomada));
        $audit = AuditLog::query()->where('action', 'stock.adjustment')->sole();
        $this->assertSame([-2, 8, 'Inventário mensal'], [$audit->new_values['quantidade'], $audit->new_values['saldo_depois'], $audit->new_values['motivo']]);
        $this->assertRule('no_change', fn () => $this->stock()->adjustTo($this->pomada, 8, 'Inventário de novo', $this->recepcao));
    }

    public function test_produto_inativo_nao_recebe_entrada_mas_pode_baixar(): void
    {
        $this->pomada->update(['is_active' => false]);

        $this->assertRule('inactive_product', fn () => $this->stock()->receive($this->pomada, 1, null, null, $this->recepcao));
        $this->stock()->adjustTo($this->pomada, 0, 'Produto descontinuado', $this->recepcao);
        $this->assertSame(0, $this->stock()->balance($this->pomada));
    }

    public function test_estorno_de_movimentacao_uma_vez_e_preserva_o_original(): void
    {
        $perda = $this->stock()->issue($this->pomada, 3, StockMovementKind::Loss, 'Lançado errado', $this->recepcao);

        $r = $this->stock()->reverse($perda, 'Perda lançada por engano', $this->recepcao);

        $this->assertSame([3, StockMovementKind::Reversal, $perda->id, 10], [$r->quantity, $r->kind, $r->reverses_movement_id, $r->balance_after]);
        $this->assertSame(-3, $perda->fresh()?->quantity, 'o original não muda');
        $this->assertRule('already_reversed', fn () => $this->stock()->reverse($perda, 'De novo', $this->recepcao));
        $this->assertRule('not_reversible', fn () => $this->stock()->reverse($r, 'Estorno do estorno', $this->recepcao));
    }

    public function test_estorno_que_deixaria_negativo_e_recusado(): void
    {
        $entrada = $this->stock()->receive($this->pomada, 5, null, 'Compra', $this->recepcao);
        $this->stock()->issue($this->pomada, 14, StockMovementKind::Usage, 'Uso', $this->recepcao);

        $this->assertRule('insufficient', fn () => $this->stock()->reverse($entrada, 'Compra devolvida', $this->recepcao));
    }

    public function test_repetir_a_mesma_requisicao_nao_duplica(): void
    {
        $chave = $this->key();
        $a = $this->stock()->receive($this->pomada, 5, null, 'Compra', $this->recepcao, $chave);
        $b = $this->stock()->receive($this->pomada, 5, null, 'Compra', $this->recepcao, $chave);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(15, $this->stock()->balance($this->pomada));
    }

    public function test_movimentacao_e_historico_imutavel(): void
    {
        $m = $this->stock()->receive($this->pomada, 5, null, 'Compra', $this->recepcao);

        $this->expectException(DomainRuleViolation::class);
        $m->update(['quantity' => 50]);
    }

    public function test_situacao_frente_ao_minimo(): void
    {
        $this->pomada->update(['min_stock' => 10]);

        $this->assertSame('low', $this->stock()::situation($this->pomada, 10));
        $this->assertSame('ok', $this->stock()::situation($this->pomada, 11));
        $this->assertSame('out', $this->stock()::situation($this->pomada, 0));
    }
}

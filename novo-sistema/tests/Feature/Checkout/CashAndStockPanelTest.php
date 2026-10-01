<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Finance\Enums\CashSessionStatus;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Caixa, produtos e estoque pelo painel: telas, permissoes, adulteracao.
 */
class CashAndStockPanelTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    private function as(User $u): static
    {
        return $this->actingAs($u, 'web');
    }

    // --- Caixa -----------------------------------------------------------------------------------

    public function test_recepcao_abre_movimenta_e_fecha_o_caixa(): void
    {
        $this->as($this->recepcao)->get(route('panel.cash.index'))->assertOk()->assertSee('Abrir caixa');
        $this->as($this->recepcao)->post(route('panel.cash.open'), ['opening_float' => '100,00'])->assertSessionHasNoErrors();
        $s = CashSession::query()->sole();

        $this->as($this->recepcao)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'supply', 'amount' => '50,00', 'reason' => 'Reforço de troco'])->assertSessionHasNoErrors();
        $this->as($this->recepcao)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'withdrawal', 'amount' => '30,00', 'reason' => 'Depósito'])->assertSessionHasNoErrors();
        $this->as($this->recepcao)->get(route('panel.cash.index'))->assertOk()->assertSee('R$ 120,00')->assertSee('Sangria')->assertSee('Reforço de troco');

        $this->as($this->recepcao)->post(route('panel.cash.close', $s), ['counted' => '115,00'])->assertSessionHasErrors('cash');
        $this->as($this->recepcao)->post(route('panel.cash.close', $s), ['counted' => '115,00', 'closing_notes' => 'Faltou troco devolvido'])
            ->assertRedirect(route('panel.cash.show', $s));

        $s->refresh();
        $this->assertSame([CashSessionStatus::Closed, -500], [$s->status, $s->difference_cents]);
        $this->as($this->recepcao)->get(route('panel.cash.show', $s))->assertOk()->assertSee('-R$ 5,00')->assertSee('Faltou troco devolvido');
        $this->as($this->recepcao)->get(route('panel.cash.index'))->assertOk()->assertSee('Abrir caixa')->assertSee('Caixas fechados');
    }

    public function test_valores_invalidos_no_caixa(): void
    {
        $this->as($this->recepcao)->post(route('panel.cash.open'), ['opening_float' => 'cem'])->assertSessionHasErrors('opening_float');
        $this->as($this->recepcao)->post(route('panel.cash.open'), ['opening_float' => '-10'])->assertSessionHasErrors('opening_float');
        $this->assertSame(0, CashSession::query()->count());

        $this->openCash();
        $this->as($this->recepcao)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'withdrawal', 'amount' => '0', 'reason' => 'Nada'])->assertSessionHasErrors('amount');
        $this->as($this->recepcao)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'bonus', 'amount' => '10,00', 'reason' => 'Tipo inventado'])->assertSessionHasErrors('type');
        $this->as($this->recepcao)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'withdrawal', 'amount' => '1000,00', 'reason' => 'Mais que tem'])->assertSessionHasErrors('cash');
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_movimento_repetido_nao_duplica(): void
    {
        $this->openCash();
        $dados = ['request_key' => $this->key(), 'type' => 'supply', 'amount' => '50,00', 'reason' => 'Reforço'];

        $this->as($this->recepcao)->post(route('panel.cash.move'), $dados);
        $this->as($this->recepcao)->post(route('panel.cash.move'), $dados);

        $this->assertSame(1, CashMovement::query()->count());
    }

    public function test_financeiro_so_consulta_o_caixa(): void
    {
        $s = $this->openCash();
        $fin = User::factory()->role(StaffRole::Finance)->create();

        $this->as($fin)->get(route('panel.cash.index'))->assertOk()->assertDontSee('Fechar caixa');
        $this->as($fin)->post(route('panel.cash.move'), ['request_key' => $this->key(), 'type' => 'supply', 'amount' => '1,00', 'reason' => 'Teste'])->assertForbidden();
        $this->as($fin)->post(route('panel.cash.close', $s), ['counted' => '100,00'])->assertForbidden();
        $this->assertTrue($s->fresh()?->isOpen());
    }

    // --- Produtos --------------------------------------------------------------------------------

    public function test_gerente_cadastra_edita_e_desativa_produto(): void
    {
        $g = User::factory()->manager()->create();

        $this->as($g)->get(route('panel.products.create'))->assertOk();
        $this->as($g)->post(route('panel.products.store'), [
            'name' => 'Óleo para barba', 'sku' => 'olb-01', 'unit' => 'fr', 'price' => '42,90', 'cost' => '18,00', 'min_stock' => '3',
        ])->assertSessionHasNoErrors();
        $p = Product::query()->where('name', 'Óleo para barba')->sole();
        $this->assertSame(['OLB-01', 4290, 1800, 3, 'fr'], [$p->sku, $p->price_cents, $p->cost_cents, $p->min_stock, $p->unit]);

        $this->as($g)->put(route('panel.products.update', $p), [
            'version' => $p->lock_version, 'name' => 'Óleo para barba', 'unit' => 'fr', 'price' => '45,00', 'cost' => '18,00',
        ])->assertSessionHasNoErrors();
        $this->assertSame(4500, $p->fresh()?->price_cents);
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'Product')->where('action', 'updated')->exists());

        $this->as($g)->post(route('panel.products.status', $p), ['active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()?->is_active);
        $this->as($g)->get(route('panel.products.index', ['situacao' => 'inativos']))->assertOk()->assertSee('Óleo para barba');
    }

    public function test_insumo_sem_preco_de_venda(): void
    {
        $g = User::factory()->manager()->create();

        $this->as($g)->post(route('panel.products.store'), ['name' => 'Toalha descartável', 'unit' => 'pct', 'cost' => '9,90'])->assertSessionHasNoErrors();

        $this->assertNull(Product::query()->where('name', 'Toalha descartável')->sole()->price_cents);
    }

    public function test_edicao_simultanea_de_produto_nao_sobrescreve(): void
    {
        $g = User::factory()->manager()->create();
        $v = $this->pomada->lock_version;

        $this->as($g)->put(route('panel.products.update', $this->pomada), ['version' => $v, 'name' => 'Pomada A', 'unit' => 'un', 'price' => '36,00'])->assertSessionHasNoErrors();
        $this->as($g)->put(route('panel.products.update', $this->pomada), ['version' => $v, 'name' => 'Pomada B', 'unit' => 'un', 'price' => '37,00'])->assertSessionHasErrors('version');

        $this->assertSame('Pomada A', $this->pomada->fresh()?->name);
    }

    public function test_exclusao_so_sem_historico(): void
    {
        $g = User::factory()->manager()->create();
        $novo = Product::factory()->create(['name' => 'Nunca usado']);

        $this->as($g)->get(route('panel.products.edit', $this->pomada))->assertOk()->assertDontSee('Excluir produto');
        $this->as($g)->delete(route('panel.products.destroy', $this->pomada))->assertSessionHasErrors('product');
        $this->assertNotNull($this->pomada->fresh());

        $this->as($g)->delete(route('panel.products.destroy', $novo))->assertSessionHasNoErrors();
        $this->assertNull(Product::withTrashed()->find($novo->id), 'exclusão física: nunca foi usado');
    }

    public function test_saldo_nao_e_editavel_pelo_cadastro(): void
    {
        $g = User::factory()->manager()->create();

        $this->as($g)->put(route('panel.products.update', $this->pomada), [
            'version' => $this->pomada->lock_version, 'name' => 'Pomada modeladora', 'unit' => 'un', 'price' => '35,00', 'quantity' => 999, 'stock' => 999,
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }

    public function test_recepcao_consulta_produtos_sem_cadastrar(): void
    {
        $this->as($this->recepcao)->get(route('panel.products.index'))->assertOk()->assertSee('Pomada modeladora')->assertDontSee('Novo produto');
        $this->as($this->recepcao)->post(route('panel.products.store'), ['name' => 'X', 'unit' => 'un'])->assertForbidden();
        $this->as($this->recepcao)->post(route('panel.products.status', $this->pomada), ['active' => 0])->assertForbidden();
    }

    // --- Estoque ---------------------------------------------------------------------------------

    public function test_entrada_saida_ajuste_e_estorno_pelo_painel(): void
    {
        $g = User::factory()->manager()->create();
        $url = fn (string $r) => route($r, $this->pomada);

        $this->as($g)->get($url('panel.stock.show'))->assertOk()->assertSee('Saldo');
        $this->as($g)->post($url('panel.stock.receive'), ['request_key' => $this->key(), 'quantity' => 5, 'unit_cost' => '14,00', 'reason' => 'NF 123'])->assertSessionHasNoErrors();
        $this->as($g)->post($url('panel.stock.issue'), ['request_key' => $this->key(), 'kind' => 'loss', 'quantity' => 2, 'reason' => 'Frasco quebrado'])->assertSessionHasNoErrors();
        $this->as($g)->post($url('panel.stock.adjust'), ['request_key' => $this->key(), 'counted' => 12, 'reason' => 'Inventário'])->assertSessionHasNoErrors();
        $this->assertSame(12, $this->stock()->balance($this->pomada));

        $perda = StockMovement::query()->where('kind', 'loss')->sole();
        $this->as($g)->post(route('panel.stock.reverse', [$this->pomada, $perda]), ['request_key' => $this->key(), 'reason' => 'Lançado errado'])->assertSessionHasNoErrors();
        $this->assertSame(14, $this->stock()->balance($this->pomada));
        $this->as($g)->get($url('panel.stock.show'))->assertOk()->assertSee('Estornada')->assertSee('Frasco quebrado');
    }

    public function test_quantidade_invalida_ou_saldo_negativo(): void
    {
        $g = User::factory()->manager()->create();

        $this->as($g)->post(route('panel.stock.issue', $this->pomada), ['request_key' => $this->key(), 'kind' => 'loss', 'quantity' => 0, 'reason' => 'Zero'])->assertSessionHasErrors('quantity');
        $this->as($g)->post(route('panel.stock.issue', $this->pomada), ['request_key' => $this->key(), 'kind' => 'loss', 'quantity' => 'dez', 'reason' => 'Texto'])->assertSessionHasErrors('quantity');
        $this->as($g)->post(route('panel.stock.issue', $this->pomada), ['request_key' => $this->key(), 'kind' => 'sale', 'quantity' => 1, 'reason' => 'Tipo proibido'])->assertSessionHasErrors('kind');
        $this->as($g)->post(route('panel.stock.issue', $this->pomada), ['request_key' => $this->key(), 'kind' => 'loss', 'quantity' => 11, 'reason' => 'Mais que tem'])->assertSessionHasErrors('stock');

        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }

    public function test_recepcao_nao_ajusta_inventario(): void
    {
        $this->as($this->recepcao)->post(route('panel.stock.receive', $this->pomada), ['request_key' => $this->key(), 'quantity' => 1])->assertSessionHasNoErrors();
        $this->as($this->recepcao)->post(route('panel.stock.adjust', $this->pomada), ['request_key' => $this->key(), 'counted' => 50, 'reason' => 'Tentativa'])->assertForbidden();
        $m = StockMovement::query()->latest('id')->first();
        $this->as($this->recepcao)->post(route('panel.stock.reverse', [$this->pomada, $m]), ['request_key' => $this->key(), 'reason' => 'Tentativa'])->assertForbidden();

        $this->assertSame(11, $this->stock()->balance($this->pomada));
    }

    public function test_movimento_de_outro_produto_na_url_e_recusado(): void
    {
        $g = User::factory()->manager()->create();
        $daLamina = StockMovement::query()->where('product_id', $this->lamina->id)->first();

        $this->as($g)->post(route('panel.stock.reverse', [$this->pomada, $daLamina]), ['request_key' => $this->key(), 'reason' => 'Tentativa'])->assertNotFound();
        $this->assertSame(100, $this->stock()->balance($this->lamina));
    }

    public function test_venda_de_atendimento_nao_e_estornada_pela_tela_de_estoque(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $at = $this->attendances()->complete($at, $this->pay(8500), $this->key(), $this->recepcao);
        $venda = StockMovement::query()->where('attendance_id', $at->id)->sole();

        $this->as(User::factory()->manager()->create())->post(route('panel.stock.reverse', [$this->pomada, $venda]), ['request_key' => $this->key(), 'reason' => 'Tentativa'])
            ->assertSessionHasErrors('stock');
        $this->assertSame(9, $this->stock()->balance($this->pomada));
    }

    public function test_filtro_estoque_baixo(): void
    {
        $this->pomada->update(['min_stock' => 10]);

        $this->as($this->recepcao)->get(route('panel.products.index', ['situacao' => 'baixo']))->assertOk()->assertSee('Pomada modeladora')->assertSee('Estoque baixo')->assertDontSee('Lâmina descartável');
    }
}

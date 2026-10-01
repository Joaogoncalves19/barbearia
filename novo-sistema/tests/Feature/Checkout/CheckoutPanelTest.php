<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Atendimento pelo painel: fluxo completo, permissoes por papel, acesso
 * horizontal (IDOR), requisicao adulterada e repetida.
 */
class CheckoutPanelTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    private User $barbeiroJoao;

    private Professional $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        $this->barbeiroJoao = User::factory()->role(StaffRole::Professional)->create();
        $this->joao->update(['user_id' => $this->barbeiroJoao->id]);
        $this->maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->maria->services()->attach($this->corte->id);
    }

    private function as(User $u): static
    {
        return $this->actingAs($u, 'web');
    }

    // --- Fluxo completo --------------------------------------------------------------------------

    public function test_recepcao_do_agendamento_a_conclusao_pelo_painel(): void
    {
        $ag = $this->todayAppointment();
        $this->openCash();

        $this->as($this->recepcao)->get(route('panel.appointments.show', $ag))->assertOk()->assertSee('Cliente chegou: abrir atendimento');
        $r = $this->as($this->recepcao)->post(route('panel.attendances.open', $ag));
        $at = Attendance::query()->sole();
        $r->assertRedirect(route('panel.attendances.show', $at));

        $this->as($this->recepcao)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('Corte')->assertSee('R$ 50,00')->assertSee('Iniciar atendimento');
        $this->as($this->recepcao)->post(route('panel.attendances.start', $at))->assertRedirect();
        $this->as($this->recepcao)->post(route('panel.attendances.products.store', $at), ['product_id' => $this->pomada->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->as($this->recepcao)->post(route('panel.attendances.consumptions.store', $at), ['consumption_product_id' => $this->lamina->id, 'consumption_quantity' => 1])->assertSessionHasNoErrors();
        $this->as($this->recepcao)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('R$ 85,00')->assertSee('Concluir e receber');

        $this->as($this->recepcao)->post(route('panel.attendances.complete', $at), [
            'completion_key' => $this->key(),
            'payments' => [
                ['method' => 'pix', 'amount' => '50,00', 'tip' => ''],
                ['method' => 'cash', 'amount' => '35,00', 'tip' => '5,00'],
            ],
        ])->assertRedirect(route('panel.attendances.show', $at))->assertSessionHas('status');

        $at->refresh();
        $this->assertSame([AttendanceStatus::Completed, 8500, 500], [$at->status, $at->total_cents, $at->tip_cents]);
        $this->assertSame(AppointmentStatus::Completed, $ag->fresh()?->status);
        $this->assertSame(2, Payment::query()->count());
        $this->as($this->recepcao)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('Pagamentos')->assertSee('Atendimento concluído.');
        $this->as($this->recepcao)->get(route('panel.attendances.index'))->assertOk()->assertSee($at->code);
    }

    public function test_repetir_o_envio_da_conclusao_nao_duplica(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $dados = ['completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => '50,00']]];

        $this->as($this->recepcao)->post(route('panel.attendances.complete', $at), $dados)->assertSessionHasNoErrors();
        $this->as($this->recepcao)->post(route('panel.attendances.complete', $at), $dados)->assertSessionHasNoErrors();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, CashMovement::query()->count());
    }

    public function test_valor_que_nao_fecha_mostra_erro_e_nao_grava(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();

        $this->as($this->recepcao)->from(route('panel.attendances.show', $at))->post(route('panel.attendances.complete', $at), [
            'completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => '40,00']],
        ])->assertRedirect(route('panel.attendances.show', $at))->assertSessionHasErrors('complete');

        $this->as($this->recepcao)->from(route('panel.attendances.show', $at))->post(route('panel.attendances.complete', $at), [
            'completion_key' => $this->key(), 'payments' => [['method' => 'unknown', 'amount' => '50,00']],
        ])->assertSessionHasErrors('payments.0.method');

        $this->as($this->recepcao)->from(route('panel.attendances.show', $at))->post(route('panel.attendances.complete', $at), [
            'completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => 'cinquenta']],
        ])->assertSessionHasErrors('payments.0.amount');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()?->status);
    }

    public function test_encaixe_pelo_painel(): void
    {
        $this->clockAt('09:02');
        $this->as($this->recepcao)->get(route('panel.attendances.create'))->assertOk()->assertSee('Encaixe');
        $this->as($this->recepcao)->post(route('panel.attendances.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->joao->id, 'contact_name' => 'Visitante Fictício',
        ])->assertRedirect();

        $at = Attendance::query()->sole();
        $this->assertSame(['walk_in', 'Visitante Fictício'], [$at->source->value, $at->customer_name]);
    }

    // --- Permissoes ------------------------------------------------------------------------------

    public function test_desconto_so_com_permissao_propria(): void
    {
        $at = $this->startedAttendance();
        $dados = ['discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Cliente frequente'];

        $this->as($this->recepcao)->post(route('panel.attendances.discount.store', $at), $dados)->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.discount.store', $at), $dados)->assertForbidden();
        $this->assertSame(0, $at->discounts()->count());

        $this->as(User::factory()->manager()->create())->post(route('panel.attendances.discount.store', $at), $dados)->assertSessionHasNoErrors();
        $this->assertSame(500, $at->discounts()->sole()->amount_cents);
    }

    public function test_desconto_invalido_e_recusado(): void
    {
        $at = $this->startedAttendance();
        $gerente = User::factory()->manager()->create();

        foreach (['0', '150', 'abc', '-5'] as $valor) {
            $this->as($gerente)->post(route('panel.attendances.discount.store', $at), ['discount_type' => 'percent', 'discount_value' => $valor, 'discount_reason' => 'Motivo'])
                ->assertSessionHasErrors('discount_value');
        }
        $this->as($gerente)->post(route('panel.attendances.discount.store', $at), ['discount_type' => 'fixed', 'discount_value' => '5,00', 'discount_reason' => ''])
            ->assertSessionHasErrors('discount_reason');
        $this->assertSame(0, $at->discounts()->count());
    }

    public function test_profissional_ve_e_conclui_so_os_proprios(): void
    {
        $this->openCash();
        $meu = $this->startedAttendance();
        $daMaria = $this->walkIn($this->maria, name: 'Cliente da Maria');

        $this->as($this->barbeiroJoao)->get(route('panel.attendances.index'))->assertOk()->assertSee($meu->code)->assertDontSee($daMaria->code);
        $this->as($this->barbeiroJoao)->get(route('panel.attendances.show', $daMaria))->assertNotFound();
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.start', $daMaria))->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.cancel', $daMaria), ['reason' => 'Tentativa'])->assertForbidden();

        $this->as($this->barbeiroJoao)->post(route('panel.attendances.complete', $meu), [
            'completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => '50,00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(AttendanceStatus::Completed, $meu->fresh()?->status);
        $this->assertSame(AttendanceStatus::Open, $daMaria->fresh()?->status);
    }

    public function test_profissional_nao_abre_encaixe_para_outro_profissional(): void
    {
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.store'), [
            'service_id' => $this->corte->id, 'professional_id' => $this->maria->id, 'contact_name' => 'Visitante',
        ])->assertForbidden();
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_profissional_nao_acessa_caixa_nem_estoque(): void
    {
        foreach ([
            route('panel.cash.index'), route('panel.products.index'), route('panel.stock.show', $this->pomada),
        ] as $url) {
            $this->as($this->barbeiroJoao)->get($url)->assertForbidden();
        }
        $this->as($this->barbeiroJoao)->post(route('panel.cash.open'), ['opening_float' => '0'])->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.stock.adjust', $this->pomada), ['request_key' => $this->key(), 'counted' => 0, 'reason' => 'Tentativa'])->assertForbidden();
        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }

    public function test_estorno_so_para_quem_pode(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);
        $pg = $at->payments()->sole();
        $dados = ['request_key' => $this->key(), 'refund_amount' => '10,00', 'refund_reason' => 'Reclamação'];

        $this->as($this->recepcao)->post(route('panel.attendances.refund', [$at, $pg]), $dados)->assertForbidden();
        $this->as(User::factory()->manager()->create())->post(route('panel.attendances.refund', [$at, $pg]), $dados)->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.refund', [$at, $pg]), $dados)->assertForbidden();
        $this->assertSame(0, Payment::query()->where('kind', 'refund')->count());

        $financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->as($financeiro)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('Estornar');
        $this->as($financeiro)->post(route('panel.attendances.refund', [$at, $pg]), $dados)->assertSessionHasNoErrors();
        $this->assertSame(1000, Payment::query()->where('kind', 'refund')->sole()->amount_cents);
    }

    public function test_financeiro_ve_mas_nao_opera_atendimento(): void
    {
        $at = $this->startedAttendance();
        $financeiro = User::factory()->role(StaffRole::Finance)->create();

        $this->as($financeiro)->get(route('panel.attendances.show', $at))->assertOk()->assertDontSee('Concluir e receber');
        $this->as($financeiro)->post(route('panel.attendances.products.store', $at), ['product_id' => $this->pomada->id, 'quantity' => 1])->assertForbidden();
        $this->as($financeiro)->post(route('panel.cash.open'), ['opening_float' => '0'])->assertForbidden();
    }

    // --- IDs manipulados, requisicao adulterada ----------------------------------------------------

    public function test_ids_de_outro_atendimento_nao_passam(): void
    {
        $this->openCash();
        $a = $this->startedAttendance();
        $b = $this->walkIn($this->maria, name: 'Outro Cliente');
        $itemDoB = $b->items()->sole();

        $this->as($this->recepcao)->delete(route('panel.attendances.items.destroy', [$a, $itemDoB]))->assertNotFound();
        $this->assertSame(1, $b->items()->count());

        $b = $this->attendances()->start($b, $this->recepcao);
        $b = $this->attendances()->complete($b, $this->pay(5000), $this->key(), $this->recepcao);
        $financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->as($financeiro)->post(route('panel.attendances.refund', [$a, $b->payments()->sole()]), [
            'request_key' => $this->key(), 'refund_amount' => '10,00', 'refund_reason' => 'Tentativa',
        ])->assertNotFound();

        $this->as($this->recepcao)->get('/painel/atendimentos/AT-NAOEXISTE')->assertNotFound();
    }

    public function test_concluido_nao_aceita_alteracao_pelo_painel(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->as($this->recepcao)->post(route('panel.attendances.products.store', $at), ['product_id' => $this->pomada->id, 'quantity' => 1])
            ->assertSessionHasErrors('attendance');
        $this->as($this->recepcao)->delete(route('panel.attendances.items.destroy', [$at, $at->items()->sole()]))->assertSessionHasErrors('attendance');
        $this->as(User::factory()->manager()->create())->post(route('panel.attendances.discount.store', $at), ['discount_type' => 'fixed', 'discount_value' => '10,00', 'discount_reason' => 'Depois de pago'])
            ->assertSessionHasErrors('attendance');
        $this->as($this->recepcao)->post(route('panel.attendances.cancel', $at), ['reason' => 'Depois de pago'])->assertSessionHasErrors('attendance');

        $at = $at->fresh();
        $this->assertSame([5000, AttendanceStatus::Completed], [$at?->total_cents, $at?->status]);
        $this->assertSame(1, $at?->items()->count());
    }

    public function test_campos_extras_no_formulario_sao_ignorados(): void
    {
        $at = $this->startedAttendance();

        // Tenta mandar o preco junto: o preco vem sempre do catalogo.
        $this->as($this->recepcao)->post(route('panel.attendances.products.store', $at), [
            'product_id' => $this->pomada->id, 'quantity' => 1, 'unit_price_cents' => 1, 'price' => '0,01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3500, $at->items()->where('product_id', $this->pomada->id)->sole()->unit_price_cents);
    }

    // --- Cliente ---------------------------------------------------------------------------------

    public function test_cliente_ve_so_o_proprio_comprovante(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000, PaymentMethod::Cash), $this->key(), $this->recepcao);
        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.show', $at))->assertOk()->assertSee('R$ 50,00')->assertSee('Dinheiro');
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', $at->appointment))->assertSee('Ver comprovante');
    }

    public function test_cliente_nao_ve_comprovante_de_outro_cliente(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->actingAs(Customer::factory()->create(), 'customer')->get(route('account.attendances.show', $at))->assertNotFound();
    }

    public function test_cliente_e_visitante_nao_entram_no_painel(): void
    {
        $at = $this->startedAttendance();

        $this->get(route('panel.attendances.show', $at))->assertRedirect();
        $this->actingAs($this->cliente, 'customer')->get(route('panel.attendances.show', $at))->assertRedirect();
        $this->actingAs($this->cliente, 'customer')->get(route('panel.cash.index'))->assertRedirect();
    }

    public function test_cliente_nao_ve_atendimento_ainda_aberto(): void
    {
        $at = $this->startedAttendance();

        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.show', $at))->assertNotFound();
    }

    // --- Devolucao ao estoque ---------------------------------------------------------------------

    public function test_devolucao_ao_estoque_pelo_atendimento(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $at = $this->attendances()->complete($at, $this->pay(8500), $this->key(), $this->recepcao);
        $venda = StockMovement::query()->where('attendance_id', $at->id)->sole();
        $dados = ['request_key' => $this->key(), 'return_reason' => 'Cliente devolveu'];

        $this->as($this->recepcao)->post(route('panel.attendances.return-stock', [$at, $venda]), $dados)->assertForbidden();
        $this->as(User::factory()->manager()->create())->post(route('panel.attendances.return-stock', [$at, $venda]), $dados)->assertSessionHasNoErrors();

        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }
}

<?php

namespace Tests\Feature\Data;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\StockLedger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAdjustment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Scheduling\Services\AppointmentPricing;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryAndMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function item(Appointment $ag, int $preco, ItemType $tipo = ItemType::Service, ?Service $s = null): AppointmentItem
    {
        return AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => $tipo, 'service_id' => $s?->id, 'name' => $s->name ?? 'Item',
            'unit_price_cents' => $preco, 'price_source' => PriceSource::CatalogAtBooking]);
    }

    public function test_mudar_o_catalogo_nao_muda_o_que_ja_foi_vendido(): void
    {
        $servico = Service::factory()->create(['name' => 'Corte', 'price_cents' => 4500]);
        $ag = Appointment::factory()->create();
        $this->item($ag, $servico->price_cents, ItemType::Service, $servico);

        $servico->update(['name' => 'Corte Premium', 'price_cents' => 6000]);
        $servico->delete();

        $item = $ag->items()->first();
        $this->assertSame('Corte', $item->name);
        $this->assertSame(4500, $item->unit_price_cents);
        $this->assertSame('Corte Premium', $item->service->name, 'o vinculo ao catalogo continua navegavel apos soft delete');
    }

    public function test_editar_o_cadastro_do_cliente_nao_reescreve_o_agendamento(): void
    {
        $cliente = Customer::factory()->create(['name' => 'Ana Antiga', 'phone' => '(11) 91111-2222']);
        $ag = Appointment::factory()->create(['customer_id' => $cliente->id, 'customer_name' => $cliente->name, 'customer_phone' => $cliente->phone]);
        $cliente->update(['name' => 'Ana Nova', 'phone' => '(11) 93333-4444']);
        $ag->refresh();
        $this->assertSame('Ana Antiga', $ag->customer_name);
        $this->assertSame('+5511911112222', $ag->customer_phone);
    }

    public function test_totais_exatos_em_centavos_sem_erro_de_float(): void
    {
        $ag = Appointment::factory()->create();
        // 0,10 + 0,20 = 0,30 e 1,10 x 3 = 3,30: casos classicos que quebram com float.
        $this->item($ag, 10);
        $this->item($ag, 20);
        AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Product, 'name' => 'Produto', 'quantity' => 3, 'unit_price_cents' => 110, 'price_source' => PriceSource::Recorded]);

        $t = app(AppointmentPricing::class)->totals($ag);
        $this->assertSame(360, $t['subtotal']->cents);
        $this->assertSame(0, $t['discount']->cents);
        $this->assertSame('R$ 3,60', $t['total']->format());
    }

    public function test_desconto_so_sobre_servicos_e_limitado_a_eles(): void
    {
        $ag = Appointment::factory()->create();
        $this->item($ag, 4500);
        $this->item($ag, 2890, ItemType::Product);
        AppointmentAdjustment::create(['appointment_id' => $ag->id, 'kind' => AdjustmentKind::Loyalty, 'amount_cents' => 10000]);

        app(AppointmentPricing::class)->refresh($ag);
        $ag->refresh();
        $this->assertSame(7390, $ag->subtotal_cents);
        $this->assertSame(4500, $ag->discount_cents, 'desconto limitado ao valor dos servicos');
        $this->assertSame(2890, $ag->total_cents, 'produto nunca e descontado (regra do sistema atual)');
    }

    public function test_total_desconhecido_quando_algum_preco_antigo_e_desconhecido(): void
    {
        $ag = Appointment::factory()->create();
        $this->item($ag, 4500);
        AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Service, 'name' => 'Removido', 'unit_price_cents' => null, 'price_source' => PriceSource::LegacyUnknown]);
        $t = app(AppointmentPricing::class)->totals($ag);
        $this->assertNull($t['total']);
    }

    public function test_pontos_sao_um_razao(): void
    {
        $ledger = app(LoyaltyLedger::class);
        $c = Customer::factory()->create();
        $ledger->credit($c, 30, LoyaltyEntryKind::Earned, 'Visita');
        $ledger->debit($c, 10, LoyaltyEntryKind::Redeemed, 'Resgate');
        $this->assertSame(20, $ledger->balance($c));

        try {
            $ledger->debit($c, 21, LoyaltyEntryKind::Redeemed);
            $this->fail('Saldo ficou negativo');
        } catch (DomainRuleViolation) {
            $this->assertSame(20, $ledger->balance($c));
        }

        $this->expectException(DomainRuleViolation::class);
        LoyaltyEntry::first()->update(['points' => 999]);
    }

    public function test_estoque_e_um_razao(): void
    {
        $ledger = app(StockLedger::class);
        $p = Product::factory()->create();
        $ledger->record($p, 10, StockMovementKind::Purchase);
        $ledger->record($p, -3, StockMovementKind::Sale);
        $this->assertSame(7, $ledger->balance($p));

        $this->expectException(DomainRuleViolation::class);
        $ledger->record($p, -8, StockMovementKind::Sale);
    }
}

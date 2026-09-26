<?php

namespace Tests\Feature\Data;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_normaliza_e_nao_repete_email_telefone_cpf(): void
    {
        $c = Customer::factory()->create(['email' => ' Ana@Exemplo.TEST ', 'phone' => '(11) 91234-5678', 'cpf' => '529.982.247-25']);
        $this->assertSame('ana@exemplo.test', $c->email);
        $this->assertSame('+5511912345678', $c->phone);
        $this->assertSame('52998224725', $c->cpf);
        $this->assertNotEmpty($c->public_id);
        $this->assertSame('unknown', $c->marketing_email_consent->value, 'consentimento comeca desconhecido');

        foreach ([['email' => 'ANA@exemplo.test'], ['phone' => '+55 11 91234 5678'], ['cpf' => '52998224725']] as $repetido) {
            try {
                Customer::factory()->create(['email' => fake()->unique()->safeEmail(), 'phone' => null, ...$repetido]);
                $this->fail('Aceitou duplicado: '.json_encode($repetido));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_valores_invalidos_viram_nulo_e_nao_colidem(): void
    {
        $a = Customer::factory()->create(['email' => 'joao@', 'phone' => '123', 'cpf' => '111.111.111-11']);
        $b = Customer::factory()->create(['email' => 'nao-e-email', 'phone' => '999', 'cpf' => '000']);
        $this->assertNull($a->email);
        $this->assertNull($b->phone);
        $this->assertNull($a->cpf);
    }

    public function test_codigos_unicos(): void
    {
        Coupon::factory()->create(['code' => 'bemvindo']);
        $this->assertSame('BEMVINDO', Coupon::first()->code);
        $this->expectException(QueryException::class);
        Coupon::factory()->create(['code' => 'BemVindo']);
    }

    public function test_uma_assinatura_vigente_por_cliente_mas_historico_permitido(): void
    {
        $cliente = Customer::factory()->create();
        $antiga = Subscription::factory()->create(['customer_id' => $cliente->id, 'status' => SubscriptionStatus::Expired]);
        $atual = Subscription::factory()->create(['customer_id' => $cliente->id, 'status' => SubscriptionStatus::Active]);
        $this->assertNull($antiga->active_customer_id);
        $this->assertSame($cliente->id, $atual->active_customer_id);

        try {
            Subscription::factory()->create(['customer_id' => $cliente->id, 'status' => SubscriptionStatus::CancelScheduled]);
            $this->fail('Duas assinaturas vigentes para o mesmo cliente');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $atual->update(['status' => SubscriptionStatus::Cancelled]);
        $this->assertNull($atual->fresh()->active_customer_id);
        Subscription::factory()->create(['customer_id' => $cliente->id, 'status' => SubscriptionStatus::Active]);
        $this->assertSame(3, $cliente->subscriptions()->count());
    }

    public function test_id_de_gateway_nao_se_repete(): void
    {
        Subscription::factory()->create(['gateway_subscription_id' => 'sub_FICTICIO_1']);
        $this->expectException(QueryException::class);
        Subscription::factory()->create(['gateway_subscription_id' => 'sub_FICTICIO_1']);
    }

    public function test_profissional_com_historico_nao_pode_ser_apagado_fisicamente(): void
    {
        $ag = Appointment::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('professionals')->where('id', $ag->professional_id)->delete();
    }

    public function test_soft_delete_de_profissional_preserva_o_historico(): void
    {
        $ag = Appointment::factory()->create();
        $ag->professional->delete();
        $this->assertSoftDeleted('professionals', ['id' => $ag->professional_id]);
        $this->assertNotNull($ag->fresh()->professional, 'agendamento continua mostrando quem atendeu');
    }

    public function test_apagar_cliente_de_fato_mantem_o_agendamento_sem_conta(): void
    {
        $cliente = Customer::factory()->create();
        $ag = Appointment::factory()->create(['customer_id' => $cliente->id, 'customer_name' => 'Nome no dia']);
        $cliente->forceDelete();
        $ag->refresh();
        $this->assertNull($ag->customer_id);
        $this->assertSame('Nome no dia', $ag->customer_name);
    }

    public function test_itens_seguem_o_agendamento_e_sobrevivem_ao_catalogo(): void
    {
        $servico = Service::factory()->create();
        $ag = Appointment::factory()->create();
        AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Service, 'service_id' => $servico->id, 'name' => $servico->name, 'unit_price_cents' => 4500, 'price_source' => PriceSource::CatalogAtBooking]);

        DB::table('services')->where('id', $servico->id)->delete(); // exclusao fisica do catalogo
        $this->assertSame(1, $ag->items()->count());
        $this->assertNull($ag->items()->first()->service_id);

        DB::table('appointments')->where('id', $ag->id)->delete();
        $this->assertSame(0, DB::table('appointment_items')->count(), 'itens apagados em cascata com o agendamento');
    }

    public function test_uma_avaliacao_por_agendamento(): void
    {
        $ag = Appointment::factory()->create();
        DB::table('reviews')->insert(['appointment_id' => $ag->id, 'rating' => 5]);
        $this->expectException(QueryException::class);
        DB::table('reviews')->insert(['appointment_id' => $ag->id, 'rating' => 4]);
    }

    public function test_cupom_um_uso_por_cliente_e_convidados_nao_colidem(): void
    {
        $cupom = Coupon::factory()->create();
        $cliente = Customer::factory()->create();
        DB::table('coupon_redemptions')->insert([['coupon_id' => $cupom->id, 'customer_id' => null], ['coupon_id' => $cupom->id, 'customer_id' => null], ['coupon_id' => $cupom->id, 'customer_id' => $cliente->id]]);
        $this->expectException(QueryException::class);
        DB::table('coupon_redemptions')->insert(['coupon_id' => $cupom->id, 'customer_id' => $cliente->id]);
    }
}

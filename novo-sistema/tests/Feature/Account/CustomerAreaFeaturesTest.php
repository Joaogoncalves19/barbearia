<?php

namespace Tests\Feature\Account;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Area do cliente (Fase 12): remarcacao com profissional que saiu do site
 * (P11-03), historico intacto, beneficios calculados no servidor, assinatura
 * (proxima cobranca, historico) e avisos so transacionais.
 */
class CustomerAreaFeaturesTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private Professional $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Mail::fake();
        $this->maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->maria->services()->attach($this->corte->id);
    }

    private function completedFor(Customer $customer, string $time = '10:00'): Attendance
    {
        $ag = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $customer,
        ));
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
    }

    // --- P11-03 -------------------------------------------------------------------------

    public function test_profissional_fora_do_site_nao_e_oferecido_nem_aceito_na_remarcacao_pela_conta(): void
    {
        $ag = $this->book($this->terca, '10:00', customer: $this->cliente);
        $this->joao->forceFill(['is_public' => false])->save();

        $tela = $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.reschedule', $ag));
        $tela->assertOk()->assertSee('não está disponível para remarcação pela conta')
            ->assertSee('profissional='.$this->maria->slug, false)->assertDontSee('profissional='.$this->joao->slug, false);

        $this->actingAs($this->cliente, 'customer')
            ->put(route('account.appointments.reschedule.update', $ag), ['data' => $this->terca, 'hora' => '15:00', 'profissional' => $this->joao->slug])
            ->assertNotFound();
        $this->assertTrue($ag->fresh()->starts_at->eq($this->at($this->terca, '10:00')));

        $this->actingAs($this->cliente, 'customer')
            ->put(route('account.appointments.reschedule.update', $ag), ['data' => $this->terca, 'hora' => '15:00', 'profissional' => $this->maria->slug])
            ->assertRedirect(route('account.appointments.show', $ag));
        $this->assertSame($this->maria->id, $ag->fresh()->professional_id);
    }

    public function test_equipe_continua_remarcando_com_profissional_fora_do_site(): void
    {
        $ag = $this->book($this->terca, '10:00', pro: $this->maria, customer: $this->cliente);
        $this->joao->forceFill(['is_public' => false])->save();

        $this->booking()->reschedule($ag, $this->at($this->terca, '15:00'), $this->joao, Channel::Staff, $this->recepcao);
        $this->assertSame($this->joao->id, $ag->fresh()->professional_id);
    }

    public function test_retirar_profissional_do_site_nao_apaga_nem_altera_o_historico(): void
    {
        $at = $this->completedFor($this->cliente);
        app(Reviews::class)->submit($this->cliente, $at, 5, 'Muito bom');
        $futuro = $this->book($this->terca, '10:00', customer: $this->cliente);
        $foto = fn () => collect(['appointments', 'appointment_items', 'attendances', 'attendance_items', 'payments', 'reviews', 'commission_entries'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
        $antes = $foto();

        $this->joao->forceFill(['is_public' => false])->save();

        $this->assertSame($antes, $foto());
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.index'))->assertOk()->assertSee('João')->assertSee($futuro->code)->assertSee($at->appointment->code);
        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.show', $at))->assertOk()->assertSee('João');
        $this->get(route('site.team'))->assertDontSee('João');
    }

    // --- Beneficios ---------------------------------------------------------------------

    public function test_beneficios_mostram_so_o_que_o_cliente_tem_direito_hoje(): void
    {
        $this->actingAs($this->cliente, 'customer')->get(route('account.loyalty'))->assertOk()->assertSee('data-entitlements-empty', false);

        // Quem ja foi atendido nao tem mais o desconto de indicacao; aniversario so no mes.
        $veterano = Customer::factory()->create();
        $this->completedFor($veterano);

        $this->policy(['birthday_enabled' => true, 'referral_enabled' => true]);
        $padrinho = Customer::factory()->create();
        $this->cliente->forceFill(['birth_date' => '1990-10-20', 'referred_by_customer_id' => $padrinho->id])->save();
        $veterano->forceFill(['birth_date' => '1990-03-20', 'referred_by_customer_id' => $padrinho->id])->save();
        $this->givePoints($this->cliente, 10);

        $tipos = fn (Customer $c) => array_map(fn ($e) => $e['kind']->value, $this->engine()->entitlements($c->fresh(), '2026-10-05'));
        $this->assertSame(['birthday', 'referral', 'loyalty'], $tipos($this->cliente));
        $this->assertSame([], $tipos($veterano));
        $this->actingAs($this->cliente->fresh(), 'customer')->get(route('account.loyalty'))->assertOk()
            ->assertSee('Mês do seu aniversário')->assertSee('Você veio por indicação')->assertSee('pontos para trocar');

        $this->activeSubscription($this->cliente);
        $this->assertSame(['subscription', 'birthday', 'referral', 'loyalty'], $tipos($this->cliente));
    }

    public function test_beneficio_nao_vem_do_navegador(): void
    {
        // Pedir "pontos" sem saldo na confirmacao nao da desconto: o servidor recalcula.
        $r = $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', [
            'servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->terca, 'hora' => '10:00', 'pontos' => 1, 'expected_total' => 0,
        ]));
        $r->assertOk()->assertSee('Pontos insuficientes');
    }

    // --- Assinatura ---------------------------------------------------------------------

    public function test_assinatura_mostra_plano_periodo_proxima_cobranca_e_historico(): void
    {
        $s = $this->activeSubscription($this->cliente);

        $this->actingAs($this->cliente, 'customer')->get(route('account.subscription'))->assertOk()
            ->assertSee('Clube do Corte')->assertSee('Período atual')->assertSee('data-next-charge', false)
            ->assertSee('data-subscription-history', false)->assertSee('Ativada')->assertDontSee('Situação atualizada pelo Stripe');

        Http::fake(['stripe.teste/v1/subscriptions/*' => Http::response($this->subObject($s, 'active', ['cancel_at_period_end' => true]))]);
        $this->manager()->cancel($s->fresh(), false, null, $this->cliente);
        $this->actingAs($this->cliente, 'customer')->get(route('account.subscription'))->assertOk()
            ->assertDontSee('data-next-charge', false)->assertSee('Cancelamento agendado')->assertSee('Manter minha assinatura');
    }

    // --- Avisos -------------------------------------------------------------------------

    public function test_avisos_da_conta_sao_so_do_servico_e_marcar_lido_vale_so_para_os_proprios(): void
    {
        CustomerNotification::query()->create(['customer_id' => $this->cliente->id, 'kind' => 'review_request', 'message' => 'Avalie seu atendimento']);

        $this->actingAs($this->cliente, 'customer')->get(route('account.notifications'))->assertOk()
            ->assertSee('data-notice-kinds', false)->assertSee('Avaliação')->assertSee('Novidades e promoções chegam só por e-mail');
    }

    public function test_listas_de_agendamentos_separam_proximos_do_historico(): void
    {
        $at = $this->completedFor($this->cliente);
        $futuro = $this->book($this->terca, '10:00', customer: $this->cliente);

        $tela = $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.index'));
        $tela->assertOk()->assertSee('data-upcoming="'.$futuro->code.'"', false)->assertSee('data-history="'.$at->appointment->code.'"', false)
            ->assertDontSee('data-history="'.$futuro->code.'"', false);
    }
}

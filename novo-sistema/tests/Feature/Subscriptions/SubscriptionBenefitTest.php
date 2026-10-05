<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionEvent;
use App\Modules\Subscriptions\Services\Plans;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Beneficio da assinatura (beneficios.md; decisoes do dono D-44 a D-46):
 * servico incluido sai de graca sem limite; um desconto so, o maior; prévia =
 * gravado = cobrado; comissao do servico coberto sobre o preco de tabela;
 * assinante nao acumula pontos (R-19); adesao no agendamento aplica o
 * beneficio quando o pagamento confirma.
 */
class SubscriptionBenefitTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        $this->dono = User::factory()->owner()->create();
    }

    private function rule(CommissionTarget $t, CommissionRuleType $type, ?int $value): void
    {
        app(CommissionRules::class)->set($t, $this->joao, null, $type, $type === CommissionRuleType::Percent ? $value : null, $type === CommissionRuleType::Fixed ? $value : null, null, $this->dono);
    }

    /** Corte (coberto) e, se pedido, Barba (nao coberta), concluido. */
    private function attend(bool $barba = false, string $time = '10:00', ?Appointment $ag = null): Attendance
    {
        $ag ??= $this->bookToday($this->cliente, $time);
        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);
        if ($barba) {
            $at = $this->attendances()->addService($at, $this->barba, $this->recepcao);
        }
        $at = $this->attendances()->start($at, $this->recepcao);
        $total = (int) app(AttendancePricing::class)->breakdown($at)->total?->cents;

        return $this->attendances()->complete($at, $total > 0 ? [new PaymentLine(PaymentMethod::Pix, $total)] : [], $this->key(), $this->recepcao);
    }

    public function test_servico_incluido_sai_de_graca_previa_gravado_cobrado(): void
    {
        $this->activeSubscription($this->cliente);

        $previa = $this->engine()->quote($this->cliente, [['total' => 5000, 'discountable' => true, 'unit' => 5000, 'service' => $this->corte->id]], '2026-10-05', new PromotionRequest(null, false));
        $ag = $this->bookToday($this->cliente, '10:00', null, null, $previa->totalCents());
        $at = $this->attend(false, '10:00', $ag);

        $this->assertSame([AdjustmentKind::Subscription, 0], [$previa->chosen?->kind, $previa->totalCents()]);
        $this->assertSame(0, $ag->total_cents);
        $this->assertNotNull($ag->adjustments()->sole()->subscription_id);
        $this->assertSame([5000, 5000, 0], [$at->subtotal_cents, $at->discount_cents, $at->total_cents]);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_sem_limite_de_uso(): void
    {
        $this->activeSubscription($this->cliente);

        foreach (['10:00', '11:00', '12:00'] as $h) {
            $this->assertSame(0, $this->bookToday($this->cliente, $h)->total_cents);
        }
    }

    public function test_servico_nao_incluido_paga_e_vale_o_maior_entre_beneficio_e_cupom(): void
    {
        $this->activeSubscription($this->cliente);
        $this->coupon('METADE', 'percent', 5000);

        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest('METADE'));
        $this->assertSame([AdjustmentKind::Subscription, 0], [$ag->adjustments()->sole()->kind, $ag->total_cents], 'R$ 50,00 da assinatura > R$ 25,00 do cupom');
        $this->assertSame(0, CouponRedemption::query()->count(), 'cupom menor nem é reservado');

        $at = $this->attend(true, '11:00');
        $this->assertSame([8000, 5000, 3000], [$at->subtotal_cents, $at->discount_cents, $at->total_cents], 'Barba (não incluída) é cobrada');
    }

    public function test_comissao_do_servico_coberto_sobre_o_preco_de_tabela(): void
    {
        $this->activeSubscription($this->cliente);
        $this->rule(CommissionTarget::Service, CommissionRuleType::Percent, 4000);

        $at = $this->attend(true);

        $linhas = CommissionEntry::query()->where('attendance_id', $at->id)->orderBy('id')->get();
        $this->assertSame([[5000, 2000], [3000, 1200]], $linhas->map(fn ($l) => [$l->base_cents, $l->amount_cents])->all(), 'Corte coberto: 40% de R$ 50,00; Barba: 40% do cobrado');
        $this->assertStringContainsString('preço de tabela', (string) ($linhas[0]->rule['observacao'] ?? ''));
    }

    public function test_regra_de_assinante_percentual_fixa_por_atendimento_e_sem_comissao(): void
    {
        $this->activeSubscription($this->cliente);
        $this->rule(CommissionTarget::Service, CommissionRuleType::Percent, 4000);

        $this->rule(CommissionTarget::Subscription, CommissionRuleType::Percent, 2000);
        $a = $this->attend(false, '10:00');
        $this->assertSame(1000, (int) CommissionEntry::query()->where('attendance_id', $a->id)->sum('amount_cents'), '20% sobre a tabela');

        $this->rule(CommissionTarget::Subscription, CommissionRuleType::Fixed, 750);
        $this->travelTo(now()->addMinute());
        $b = $this->attend(false, '11:00');
        $this->assertSame(750, (int) CommissionEntry::query()->where('attendance_id', $b->id)->sum('amount_cents'), 'valor fixo por atendimento');

        $this->rule(CommissionTarget::Subscription, CommissionRuleType::None, null);
        $this->travelTo(now()->addMinute());
        $c = $this->attend(false, '12:00');
        $this->assertSame(0, (int) CommissionEntry::query()->where('attendance_id', $c->id)->sum('amount_cents'));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_assinante_nao_acumula_pontos(): void
    {
        $this->activeSubscription($this->cliente);
        $this->attend();

        $this->assertSame(0, $this->loyalty()->balance($this->cliente), 'R-19');
    }

    public function test_beneficio_acompanha_os_itens_no_balcao(): void
    {
        $this->activeSubscription($this->cliente);
        $outro = Service::factory()->create(['name' => 'Corte infantil', 'duration_minutes' => 30, 'price_cents' => 4000]);
        $this->joao->services()->attach($outro->id);
        app(Plans::class)->newVersion($this->clube, 9900, [$this->corte->id, $outro->id], 'Inclui infantil', $this->dono);
        // Assinatura nova (versao 2): o corte infantil tambem e incluido.
        $this->cliente = Customer::factory()->create();
        $this->activeSubscription($this->cliente);

        $at = $this->attendances()->openFromAppointment($this->bookToday($this->cliente, '13:00'), $this->recepcao);
        $at = $this->attendances()->addService($at, $outro, $this->recepcao);

        $b = app(AttendancePricing::class)->breakdown($at->refresh());
        $this->assertSame([9000, 9000, 0], [$b->subtotal?->cents, $b->discount?->cents, $b->total?->cents], 'serviço incluído acrescentado na comanda também sai de graça');
    }

    public function test_cancelada_na_hora_o_atendimento_cobra_normal(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $ag = $this->bookToday($this->cliente);
        $this->assertSame(0, $ag->total_cents);

        Http::fake(['stripe.teste/v1/subscriptions/*' => Http::response($this->subObject($s, 'canceled', ['ended_at' => now()->getTimestamp(), 'canceled_at' => now()->getTimestamp()]))]);
        $this->manager()->cancel($s, true, 'Pedido do cliente', $this->dono);

        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);
        $b = app(AttendancePricing::class)->breakdown($at);
        $this->assertSame([0, 5000], [$at->discounts()->count(), $b->total?->cents], 'sem direito hoje: o benefício sai do atendimento');
    }

    public function test_beneficio_sem_direito_na_conclusao_e_recusado_com_motivo(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($this->bookToday($this->cliente), $this->recepcao), $this->recepcao);

        Http::fake(['stripe.teste/v1/subscriptions/*' => Http::response($this->subObject($s, 'canceled', ['ended_at' => now()->getTimestamp()]))]);
        $this->manager()->cancel($s, true, 'Pedido do cliente', $this->dono);

        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('não dá direito hoje');
        $this->attendances()->complete($at, [], $this->key(), $this->recepcao);
    }

    public function test_adesao_no_agendamento_aplica_o_beneficio_quando_o_pagamento_confirma(): void
    {
        $ag = $this->bookToday($this->cliente);
        $this->assertSame(5000, $ag->total_cents);

        $s = $this->activeSubscription($this->cliente, $ag);

        $ag->refresh();
        $this->assertSame([0, AdjustmentKind::Subscription], [$ag->total_cents, $ag->adjustments()->sole()->kind]);
        $this->assertSame($s->id, $ag->adjustments()->sole()->subscription_id);
        $this->assertSame(1, AppointmentEvent::query()->where('appointment_id', $ag->id)->where('type', 'adjusted')->count());
        $this->assertSame(1, SubscriptionEvent::query()->where('kind', 'benefit_applied')->count());
    }

    public function test_adesao_pelo_site_no_agendamento(): void
    {
        $this->fakeStripe();
        $escolha = ['servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15'];

        $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', $escolha))
            ->assertOk()->assertSee('Clube do Corte')->assertSee('sai de graça para assinante');

        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), [...$escolha, 'expected_total' => 5000, 'plano' => $this->clube->id])
            ->assertRedirect(route('account.subscription'));

        $s = Subscription::query()->sole();
        $this->assertSame([SubscriptionStatus::Pending, 'booking'], [$s->status, $s->origin->value]);
        $this->assertNotNull($s->signup_appointment_id);
        $this->actingAs($this->cliente, 'customer')->get(route('account.subscription'))->assertOk()->assertSee('Pagar assinatura no Stripe')->assertSee((string) $s->checkout_url, false);
    }

    public function test_sem_stripe_configurado_a_adesao_nao_aparece(): void
    {
        config(['services.stripe.secret' => '']);
        $escolha = ['servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15'];

        $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', $escolha))->assertOk()->assertDontSee('Quer assinar um plano?');
    }

    public function test_beneficio_nao_vale_depois_do_fim_pago(): void
    {
        $this->activeSubscription($this->cliente);
        $q = $this->engine()->quote($this->cliente, [['total' => 5000, 'discountable' => true, 'unit' => 5000, 'service' => $this->corte->id]], '2026-11-10', new PromotionRequest(null, false));

        $this->assertNull($q->chosen, 'agendamento depois do fim pago (e da tolerância) não tem benefício');
    }
}

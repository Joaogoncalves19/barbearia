<?php

namespace Tests\Feature\Promotions;

use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PromotionFixtures;
use Tests\TestCase;

/**
 * Regras de cada promocao (promocoes.md, fidelidade.md): cupom (R-12),
 * pontos (R-17 a R-19 e decisao do dono), aniversario (R-14), indicacao
 * (R-15), desconto manual e promocao no balcao (um so, o maior).
 */
class PromotionRulesTest extends TestCase
{
    use PromotionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotions();
    }

    private function rejected(string $trecho, \Closure $acao): void
    {
        try {
            $acao();
            $this->fail("deveria recusar: {$trecho}");
        } catch (PromotionRejected|CheckoutRuleViolation $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());
        }
    }

    private function complete($ag): void
    {
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);
        $total = (int) app(AttendancePricing::class)->breakdown($at)->total?->cents;
        $this->attendances()->complete($at, $total > 0 ? [new PaymentLine(PaymentMethod::Pix, $total)] : [], $this->key(), $this->recepcao);
    }

    // --- Cupom -----------------------------------------------------------------------------------

    public function test_cupom_reservado_ao_agendar_usado_na_conclusao_e_um_por_cliente(): void
    {
        $c = $this->coupon('BEMVINDO', 'percent', 2000, 5);

        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest(' bemvindo '));

        $uso = CouponRedemption::query()->sole();
        $this->assertSame([RedemptionStatus::Reserved, $ag->id, 1000], [$uso->status, $uso->appointment_id, $uso->discount_cents]);
        $this->assertSame([4000, 1], [$ag->total_cents, $c->fresh()?->uses_count]);
        $adj = $ag->adjustments()->sole();
        $this->assertSame([AdjustmentKind::Coupon, 2000, $uso->id], [$adj->kind, $adj->percent_bp, $adj->coupon_redemption_id], 'o agendamento guarda a regra e a reserva');

        $this->complete($ag);
        $this->assertSame(RedemptionStatus::Redeemed, $uso->fresh()?->status);

        $this->rejected('já usou este cupom', fn () => $this->bookToday($this->cliente, '11:00', new PromotionRequest('BEMVINDO')));
    }

    public function test_cupom_invalido_recusa_o_agendamento_inteiro(): void
    {
        $this->coupon('VENCIDO', 'percent', 1000, null, ['expires_on' => '2026-10-04']);
        $this->coupon('PAUSADO', 'percent', 1000, null, ['is_active' => false]);
        $this->coupon('ESGOTADO', 'percent', 1000, 1, ['uses_count' => 1]);

        $this->rejected('venceu em 04/10/2026', fn () => $this->bookToday($this->cliente, '10:00', new PromotionRequest('VENCIDO')));
        $this->rejected('não está ativo', fn () => $this->bookToday($this->cliente, '10:00', new PromotionRequest('PAUSADO')));
        $this->rejected('limite de usos', fn () => $this->bookToday($this->cliente, '10:00', new PromotionRequest('ESGOTADO')));
        $this->rejected('não encontrado', fn () => $this->bookToday($this->cliente, '10:00', new PromotionRequest('NAOEXISTE')));
        $this->assertSame(0, Appointment::query()->count(), 'nada gravado');
    }

    public function test_cancelar_ou_faltar_libera_o_cupom(): void
    {
        $c = $this->coupon('VOLTA', 'fixed', 1000, 1);
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest('VOLTA'));
        $this->assertSame(1, $c->fresh()?->uses_count);

        $this->booking()->cancel($ag, Channel::Staff, $this->recepcao, 'Desistiu');

        $this->assertSame([RedemptionStatus::Released, null, 0], [CouponRedemption::query()->sole()->status, CouponRedemption::query()->sole()->active_key, $c->fresh()?->uses_count]);
        $ag2 = $this->bookToday($this->cliente, '11:00', new PromotionRequest('VOLTA'));
        $this->assertSame(4000, $ag2->total_cents, 'o cliente pode usar de novo e o limite voltou');
    }

    public function test_valor_visto_diferente_do_gravado_e_recusado(): void
    {
        $this->coupon('DEZ', 'percent', 1000);

        $this->rejected('valor mudou', fn () => $this->bookToday($this->cliente, '10:00', new PromotionRequest('DEZ'), null, 5000));
        $this->assertSame([0, 0], [Appointment::query()->count(), CouponRedemption::query()->count()]);
    }

    public function test_cupom_e_imutavel_no_historico_e_regra_do_agendamento_nao_muda(): void
    {
        $c = $this->coupon('MUDA', 'percent', 1000);
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest('MUDA'));

        $c->update(['percent_bp' => 5000]);
        $this->complete($ag);

        $this->assertSame(4500, $ag->fresh()?->total_cents, 'o agendamento guardou 10%');
        $this->expectException(DomainRuleViolation::class);
        CouponRedemption::query()->sole()->update(['discount_cents' => 1]);
    }

    // --- Pontos ----------------------------------------------------------------------------------

    public function test_pontos_reservados_nao_sao_prometidos_duas_vezes_e_saem_na_conclusao(): void
    {
        $this->givePoints($this->cliente, 15);
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest(null, true));

        $this->assertSame([15, 5], [$this->loyalty()->balance($this->cliente), $this->loyalty()->available($this->cliente)], 'reservado, ainda no saldo');
        $this->assertSame(2500, $ag->adjustments()->sole()->amount_cents);
        $this->rejected('Pontos insuficientes', fn () => $this->bookToday($this->cliente, '11:00', new PromotionRequest(null, true)));

        $this->complete($ag);
        $this->assertSame(15 - 10 + 1, $this->loyalty()->balance($this->cliente), '10 resgatados, 1 ganho');
        $resgate = LoyaltyEntry::query()->where('kind', LoyaltyEntryKind::Redeemed)->sole();
        $this->assertSame([-10, LoyaltyRedemption::query()->sole()->id], [$resgate->points, $resgate->loyalty_redemption_id]);
    }

    public function test_cancelar_devolve_a_reserva_de_pontos_sem_lancamento(): void
    {
        $this->givePoints($this->cliente, 10);
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest(null, true));
        $this->booking()->cancel($ag, Channel::Staff, $this->recepcao, 'Desistiu');

        $this->assertSame([10, 10], [$this->loyalty()->balance($this->cliente), $this->loyalty()->available($this->cliente)]);
        $this->assertSame(RedemptionStatus::Released, LoyaltyRedemption::query()->sole()->status);
        $this->assertSame(0, LoyaltyEntry::query()->where('kind', LoyaltyEntryKind::Redeemed)->count());
    }

    public function test_ganho_por_valor_e_ajuste_manual(): void
    {
        $this->policy(['loyalty_earn_mode' => 'value', 'loyalty_cents_per_point' => 1000]);
        $this->complete($this->bookToday($this->cliente));
        $this->assertSame(5, $this->loyalty()->balance($this->cliente), 'R$ 50,00 / R$ 10,00');

        $this->loyalty()->adjust($this->cliente, 7, 'Cortesia', $this->recepcao, 'chave-1');
        $this->loyalty()->adjust($this->cliente, 7, 'Cortesia', $this->recepcao, 'chave-1');
        $this->assertSame(12, $this->loyalty()->balance($this->cliente), 'mesma chave, um ajuste');
        $this->assertSame(1, AuditLog::query()->where('action', 'loyalty.adjusted')->count());

        $this->policy(['loyalty_earn_mode' => 'visit', 'loyalty_points_required' => 10]);
        $this->bookToday($this->cliente, '11:00', new PromotionRequest(null, true)); // reserva 10
        $this->rejected('Pontos insuficientes', fn () => $this->loyalty()->adjust($this->cliente, -3, 'Retirada', $this->recepcao, 'chave-2'));
        $this->rejected('Informe o motivo', fn () => $this->loyalty()->adjust($this->cliente, 1, '', $this->recepcao, 'chave-3'));
    }

    // --- Aniversario e indicacao -----------------------------------------------------------------

    public function test_aniversario_uma_vez_no_mes(): void
    {
        $this->policy(['birthday_enabled' => true]);
        $aniv = Customer::factory()->create(['birth_date' => '1985-10-30']);

        $a1 = $this->bookToday($aniv, '10:00');
        $a2 = $this->bookToday($aniv, '11:00');

        $this->assertSame([AdjustmentKind::Birthday, 4250], [$a1->adjustments()->sole()->kind, $a1->total_cents]);
        $this->assertSame(5000, $a2->total_cents, 'o segundo agendamento do mês não ganha de novo');

        $this->booking()->cancel($a1, Channel::Staff, $this->recepcao, 'Desistiu');
        $this->assertSame(4250, $this->bookToday($aniv, '12:00')->total_cents, 'cancelado não conta');
    }

    public function test_indicacao_desconto_no_primeiro_e_pontos_para_quem_indicou_uma_vez(): void
    {
        $this->policy(['referral_enabled' => true]);
        $amigo = Customer::factory()->create();
        $novo = Customer::factory()->create();
        $novo->forceFill(['referred_by_customer_id' => $amigo->id])->save();

        $ag = $this->bookToday($novo);
        $this->assertSame(4500, $ag->total_cents);
        $this->complete($ag);
        $this->assertSame(3, $this->loyalty()->balance($amigo), 'bônus para quem indicou');

        $ag2 = $this->bookToday($novo, '11:00');
        $this->assertSame(5000, $ag2->total_cents, 'só no primeiro');
        $this->complete($ag2);
        $this->assertSame(3, $this->loyalty()->balance($amigo), 'bônus uma vez só');
    }

    // --- Manual e balcao -------------------------------------------------------------------------

    public function test_manual_maior_substitui_e_libera_o_cupom_menor_e_recusado(): void
    {
        $this->coupon('DEZ', 'percent', 1000);
        $gerente = User::factory()->manager()->create();
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest('DEZ'));
        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);

        $this->rejected('maior ou igual', fn () => $this->attendances()->applyDiscount($at, Discount::fixed(300), 'Pouco', $gerente));
        $this->assertSame(RedemptionStatus::Reserved, CouponRedemption::query()->sole()->status);

        $at = $this->attendances()->applyDiscount($at, Discount::percent(3000), 'Reclamação', $gerente);
        $this->assertSame([AdjustmentKind::Manual, 1500], [$at->discounts()->sole()->kind, $at->discounts()->sole()->amount_cents]);
        $this->assertSame(RedemptionStatus::Released, CouponRedemption::query()->sole()->status, 'o cupom volta para o cliente');
    }

    public function test_promocao_no_balcao_so_se_for_maior(): void
    {
        $this->coupon('BALCAO', 'fixed', 2000);
        $this->givePoints($this->cliente, 10);
        $ag = $this->bookToday($this->cliente, '10:00', new PromotionRequest('BALCAO'));
        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);

        $at = $this->attendances()->applyPromotion($at, new PromotionRequest(null, true), $this->recepcao);

        $this->assertSame([AdjustmentKind::Loyalty, 2500], [$at->discounts()->sole()->kind, $at->discounts()->sole()->amount_cents]);
        $this->assertSame(RedemptionStatus::Released, CouponRedemption::query()->sole()->status);
        $this->rejected('maior ou igual', fn () => $this->attendances()->applyPromotion($at, new PromotionRequest('BALCAO'), $this->recepcao));
        $this->assertSame(1, AuditLog::query()->where('action', 'promotion.applied')->count());

        $at = $this->attendances()->start($at, $this->recepcao);
        $this->attendances()->complete($at, [new PaymentLine(PaymentMethod::Pix, 2500)], $this->key(), $this->recepcao);
        $this->assertSame(1, $this->loyalty()->balance($this->cliente));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_verificacao_de_integridade_acha_gravacao_direta_errada(): void
    {
        $this->coupon('DEZ', 'percent', 1000);
        $this->givePoints($this->cliente, 10);
        $outro = Customer::factory()->create();
        $this->complete($this->bookToday($this->cliente, '10:00', new PromotionRequest('DEZ')));
        $this->bookToday($this->cliente, '11:00', new PromotionRequest(null, true));
        $vale = $this->giftCards()->sell(3000, PaymentMethod::Pix, [], $this->recepcao, 'v1');
        $this->complete($this->bookToday($outro, '12:00'));
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        // Gravacoes diretas no banco (fora dos servicos), como faria um script errado.
        \DB::table('coupons')->update(['uses_count' => 0]);
        \DB::table('loyalty_entries')->where('customer_id', $this->cliente->id)->delete();
        \DB::table('cash_movements')->where('gift_card_id', $vale->id)->update(['amount_cents' => 1]);
        $at = \DB::table('attendances')->where('customer_id', $outro->id)->value('id');
        \DB::table('attendance_discounts')->insert([
            ['attendance_id' => $at, 'kind' => 'manual', 'type' => 'fixed', 'fixed_cents' => 100, 'base_cents' => 5000, 'amount_cents' => 100, 'created_at' => now()],
            ['attendance_id' => $at, 'kind' => 'manual', 'type' => 'fixed', 'fixed_cents' => 100, 'base_cents' => 5000, 'amount_cents' => 100, 'created_at' => now()],
        ]);

        $v = app(IntegrityChecker::class)->violations();
        foreach (['R39_uso_de_cupom', 'R40_resgate_de_pontos', 'R41_vale_presente', 'R42_um_desconto'] as $regra) {
            $this->assertArrayHasKey($regra, $v, $regra);
        }
    }

    public function test_promocao_exige_cliente_cadastrado(): void
    {
        $this->clockAt('09:02');
        $at = $this->attendances()->openWalkIn($this->corte, $this->joao, null, 'Visitante', null, $this->recepcao);

        $this->rejected('cliente cadastrado', fn () => $this->attendances()->applyPromotion($at, new PromotionRequest('X'), $this->recepcao));
        $this->assertSame(0, Coupon::query()->count() + CouponRedemption::query()->count());
        $this->assertNotNull(Professional::query()->first());
    }
}

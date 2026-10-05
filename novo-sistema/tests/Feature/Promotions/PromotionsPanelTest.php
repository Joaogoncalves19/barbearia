<?php

namespace Tests\Feature\Promotions;

use App\Modules\Checkout\Services\AttendancePricing;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\System\Models\AuditLog;
use Database\Factories\CustomerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PromotionFixtures;
use Tests\TestCase;

/**
 * Telas da Fase 8 pelo HTTP: permissao especifica por acao (sem acesso
 * amplo), cupom, configuracao, ajuste de pontos, venda e cancelamento de
 * vale, promocao no balcao, agendamento do cliente com cupom/pontos e a
 * conta do cliente (fidelidade e indicacao).
 */
class PromotionsPanelTest extends TestCase
{
    use PromotionFixtures, RefreshDatabase;

    private User $dono;

    private User $gerente;

    private User $financeiro;

    private User $barbeiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotions();
        $this->dono = User::factory()->owner()->create();
        $this->gerente = User::factory()->manager()->create();
        $this->financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->barbeiro = User::factory()->role(StaffRole::Professional)->create();
    }

    private function as(User $u): static
    {
        $this->flushSession();

        return $this->actingAs($u, 'web');
    }

    public function test_quem_acessa_cada_tela(): void
    {
        $c = $this->coupon('DEZ');
        $vale = $this->giftCards()->sell(3000, PaymentMethod::Pix, [], $this->recepcao, 'v1');
        $telas = [
            route('panel.coupons.index') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.coupons.create') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 403, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.coupons.edit', $c) => ['dono' => 200, 'gerente' => 200, 'recepcao' => 403, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.promotions.settings') => ['dono' => 200, 'gerente' => 403, 'recepcao' => 403, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.loyalty.customers') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.loyalty.customer', $this->cliente->public_id) => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.gift-cards.index') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
            route('panel.gift-cards.create') => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 403, 'barbeiro' => 403],
            route('panel.gift-cards.show', $vale) => ['dono' => 200, 'gerente' => 200, 'recepcao' => 200, 'financeiro' => 200, 'barbeiro' => 403],
        ];
        $quem = ['dono' => $this->dono, 'gerente' => $this->gerente, 'recepcao' => $this->recepcao, 'financeiro' => $this->financeiro, 'barbeiro' => $this->barbeiro];

        foreach ($telas as $url => $esperado) {
            foreach ($esperado as $papel => $status) {
                $this->as($quem[$papel])->get($url)->assertStatus($status);
            }
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->get($url)->assertRedirect();
        }
    }

    public function test_cupom_criado_editado_e_pausado_pelo_painel(): void
    {
        $this->as($this->gerente)->post(route('panel.coupons.store'), ['code' => 'natal-10', 'discount_type' => 'percent', 'value' => '10', 'max_uses' => 50])
            ->assertRedirect(route('panel.coupons.index'));
        $c = Coupon::query()->sole();
        $this->assertSame(['NATAL-10', 1000, 50], [$c->code, $c->percent_bp, $c->max_uses]);

        $this->as($this->gerente)->post(route('panel.coupons.store'), ['code' => 'NATAL-10', 'discount_type' => 'fixed', 'value' => '5'])->assertSessionHasErrors('code');
        $this->as($this->gerente)->post(route('panel.coupons.store'), ['code' => 'MAIS', 'discount_type' => 'percent', 'value' => '150'])->assertSessionHasErrors('value');
        $this->as($this->gerente)->post(route('panel.coupons.store'), ['code' => 'A B', 'discount_type' => 'fixed', 'value' => '5'])->assertSessionHasErrors('code');

        $this->as($this->gerente)->put(route('panel.coupons.update', $c), ['code' => 'NATAL-10', 'discount_type' => 'fixed', 'value' => '15,00'])->assertSessionHasNoErrors();
        $this->assertSame([null, 1500], [$c->fresh()?->percent_bp, $c->fresh()?->amount_cents]);
        $this->as($this->gerente)->post(route('panel.coupons.status', $c), ['active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $c->fresh()?->is_active);

        $this->as($this->recepcao)->post(route('panel.coupons.store'), ['code' => 'RECEP', 'discount_type' => 'percent', 'value' => '50'])->assertForbidden();
        $this->assertSame(1, Coupon::query()->count());
        $this->assertGreaterThanOrEqual(3, AuditLog::query()->where('auditable_type', 'Coupon')->count());
    }

    public function test_configuracao_so_do_dono_e_auditada(): void
    {
        $dados = [
            'loyalty_enabled' => '1', 'loyalty_earn_mode' => 'visit', 'loyalty_points_per_visit' => 2, 'loyalty_cents_per_point' => '10,00',
            'loyalty_points_required' => 8, 'loyalty_reward_type' => 'fixed', 'loyalty_reward_base' => 'cheapest', 'loyalty_reward_percent_bp' => '50',
            'loyalty_reward_fixed_cents' => '20,00', 'birthday_enabled' => '1', 'birthday_percent_bp' => '15', 'referral_percent_bp' => '10', 'referral_bonus_points' => 3,
        ];

        $this->as($this->gerente)->put(route('panel.promotions.settings.update'), $dados)->assertForbidden();
        $this->as($this->dono)->put(route('panel.promotions.settings.update'), $dados)->assertSessionHasNoErrors();

        $p = PromotionPolicy::current();
        $this->assertSame([2, 8, 'fixed', 2000, true, false], [$p->int('loyalty_points_per_visit'), $p->int('loyalty_points_required'), $p->string('loyalty_reward_type'), $p->int('loyalty_reward_fixed_cents'), $p->bool('birthday_enabled'), $p->bool('referral_enabled')]);
        $this->assertSame(1, AuditLog::query()->where('action', 'promotions.policy_changed')->where('actor_id', $this->dono->id)->count());
    }

    public function test_ajuste_de_pontos_com_permissao_motivo_e_chave(): void
    {
        $url = route('panel.loyalty.adjust', $this->cliente->public_id);
        $chave = (string) Str::uuid();

        $this->as($this->recepcao)->post($url, ['request_key' => $chave, 'direction' => 'credit', 'points' => 5, 'reason' => 'Cortesia'])->assertForbidden();
        $this->as($this->gerente)->post($url, ['request_key' => $chave, 'direction' => 'credit', 'points' => 5, 'reason' => 'Cortesia'])->assertSessionHasNoErrors();
        $this->as($this->gerente)->post($url, ['request_key' => $chave, 'direction' => 'credit', 'points' => 5, 'reason' => 'Cortesia']);
        $this->as($this->gerente)->post($url, ['request_key' => (string) Str::uuid(), 'direction' => 'debit', 'points' => 9, 'reason' => 'Erro'])->assertSessionHasErrors();
        $this->as($this->gerente)->post($url, ['request_key' => (string) Str::uuid(), 'direction' => 'credit', 'points' => 5, 'reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame(5, $this->loyalty()->balance($this->cliente));
        $this->assertSame($this->gerente->id, LoyaltyEntry::query()->sole()->created_by_user_id);
    }

    public function test_venda_e_cancelamento_de_vale_pelo_painel(): void
    {
        $chave = (string) Str::uuid();
        $venda = ['request_key' => $chave, 'amount' => '80,00', 'method' => 'pix', 'recipient_name' => 'Presenteado Fictício', 'expires_on' => '2027-10-05'];

        $this->as($this->financeiro)->post(route('panel.gift-cards.store'), $venda)->assertForbidden();
        $r = $this->as($this->recepcao)->post(route('panel.gift-cards.store'), $venda);
        $this->as($this->recepcao)->post(route('panel.gift-cards.store'), $venda);
        $vale = GiftCard::query()->sole();
        $r->assertRedirect(route('panel.gift-cards.show', $vale));
        $this->assertSame(8000, $vale->amount_cents);
        $this->as($this->recepcao)->post(route('panel.gift-cards.store'), [...$venda, 'request_key' => (string) Str::uuid(), 'method' => 'gift_card'])->assertSessionHasErrors('method');

        $this->as($this->recepcao)->post(route('panel.gift-cards.cancel', $vale), ['reason' => 'Desistência'])->assertForbidden();
        $this->as($this->financeiro)->post(route('panel.gift-cards.cancel', $vale), ['reason' => 'Desistência'])->assertSessionHasNoErrors();
        $this->assertSame(GiftCardStatus::Cancelled, $vale->fresh()?->status);
        $this->as($this->financeiro)->get(route('panel.gift-cards.show', $vale))->assertOk()->assertSee('Cancelado');
    }

    public function test_promocao_no_balcao_pelo_painel(): void
    {
        $this->coupon('BALCAO', 'fixed', 2000);
        $at = $this->attendances()->openFromAppointment($this->bookToday($this->cliente), $this->recepcao);
        $url = route('panel.attendances.promotion', $at);

        $this->as($this->financeiro)->post($url, ['coupon' => 'BALCAO'])->assertForbidden();
        $this->as($this->recepcao)->post($url, ['coupon' => 'NAOEXISTE'])->assertSessionHasErrors();
        $this->as($this->recepcao)->post($url, ['coupon' => 'balcao'])->assertSessionHasNoErrors();

        $this->assertSame(3000, app(AttendancePricing::class)->breakdown($at->refresh())->total?->cents);
        $this->assertSame(RedemptionStatus::Reserved, CouponRedemption::query()->sole()->status);
        $this->as($this->recepcao)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('BALCAO');
        $this->as($this->gerente)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('Vale um desconto só, o maior');
    }

    public function test_cliente_agenda_com_cupom_e_ve_o_mesmo_valor(): void
    {
        $this->coupon('ONLINE', 'percent', 2000);
        $escolha = ['servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15'];

        $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', [...$escolha, 'cupom' => 'online']))
            ->assertOk()->assertSee('Cupom ONLINE')->assertSee('R$ 40,00')->assertSee('name="expected_total" value="4000"', false);

        // A pessoa viu R$ 45,00 (outro valor): o servidor recusa e volta a confirmacao.
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), [...$escolha, 'cupom' => 'ONLINE', 'expected_total' => 4500])
            ->assertRedirect()->assertSessionHasErrors('promotion');
        $this->assertSame(0, Appointment::query()->count());

        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), [...$escolha, 'cupom' => 'ONLINE', 'expected_total' => 4000])
            ->assertSessionHasNoErrors();
        $this->assertSame(4000, Appointment::query()->sole()->total_cents);
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', Appointment::query()->sole()))
            ->assertOk()->assertSee('−R$ 10,00')->assertSee('R$ 40,00');

        $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', [...$escolha, 'hora' => '11:00', 'cupom' => 'ONLINE']))
            ->assertOk()->assertSee('já usou este cupom');
    }

    public function test_previa_com_cupom_tem_limite_e_sem_cupom_nao(): void
    {
        $escolha = ['servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->segunda, 'hora' => '10:15'];

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', $escolha))->assertOk();
        }
        $status = [];
        for ($i = 0; $i < 11; $i++) {
            $status[] = $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', [...$escolha, 'cupom' => 'TESTE'.$i]))->status();
        }

        $this->assertSame(array_fill(0, 10, 200), array_slice($status, 0, 10));
        $this->assertSame(429, $status[10], 'testar códigos de cupom em série é limitado');
    }

    public function test_conta_mostra_pontos_e_codigo_e_cadastro_por_indicacao(): void
    {
        $this->givePoints($this->cliente, 4);
        $this->cliente->forceFill(['referral_code' => null])->save();

        $this->actingAs($this->cliente, 'customer')->get(route('account.loyalty'))->assertOk()->assertSee('4')->assertSee('10 pontos');
        $this->actingAs($this->cliente, 'customer')->post(route('account.loyalty.code'))->assertSessionHas('status');
        $codigo = (string) $this->cliente->fresh()?->referral_code;
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $codigo);
        $this->actingAs($this->cliente, 'customer')->post(route('account.loyalty.code'));
        $this->assertSame($codigo, $this->cliente->fresh()?->referral_code, 'gera uma vez só');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(route('customer.register.store'), [
            'name' => 'Indicado Fictício', 'email' => 'indicado@exemplo.test', 'cpf' => CustomerFactory::fakeCpf(), 'phone' => '(11) 97777-6666',
            'password' => 'SenhaBoa123', 'password_confirmation' => 'SenhaBoa123', 'referral' => strtolower($codigo),
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->cliente->id, Customer::query()->where('email', 'indicado@exemplo.test')->value('referred_by_customer_id'));
    }
}

<?php

namespace Tests\Feature\Data;

use App\Modules\Catalog\Models\Service;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AmountSource;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use App\Modules\System\Models\Setting;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRulesTest extends TestCase
{
    use RefreshDatabase;

    private function payment(array $over = []): Payment
    {
        return Payment::create([...['attendance_id' => Attendance::factory()->create()->id, 'kind' => PaymentKind::Payment, 'method' => PaymentMethod::Pix,
            'amount_cents' => 4500, 'tip_cents' => 0, 'amount_source' => AmountSource::Recorded, 'paid_at' => now()], ...$over]);
    }

    public function test_pagamento_e_imutavel_e_se_corrige_com_estorno(): void
    {
        $p = $this->payment();
        try {
            $p->update(['amount_cents' => 1]);
            $this->fail('Editou pagamento');
        } catch (DomainRuleViolation) {
            $this->assertSame(4500, $p->fresh()->amount_cents);
        }
        try {
            $p->delete();
            $this->fail('Apagou pagamento');
        } catch (DomainRuleViolation) {
            $this->assertNotNull($p->fresh());
        }

        $estorno = $this->payment(['attendance_id' => $p->attendance_id, 'kind' => PaymentKind::Refund, 'refunds_payment_id' => $p->id]);
        $this->assertSame(0, $p->fresh()->signedAmount()->add($estorno->signedAmount())->cents);

        $this->expectException(DomainRuleViolation::class);
        $this->payment(['kind' => PaymentKind::Refund]); // estorno sem apontar o pagamento
    }

    public function test_pagamento_nao_aceita_valor_negativo_e_aceita_so_gorjeta(): void
    {
        $this->assertSame(500, $this->payment(['amount_cents' => 0, 'tip_cents' => 500])->tip_cents);
        $this->expectException(DomainRuleViolation::class);
        $this->payment(['amount_cents' => -1]);
    }

    public function test_lancamento_de_comissao_so_muda_o_vinculo_com_o_pagamento(): void
    {
        $prof = Professional::factory()->create();
        $e = CommissionEntry::create(['professional_id' => $prof->id, 'base_cents' => 10000, 'rate_bp' => 4000, 'amount_cents' => 4000]);
        $payout = CommissionPayout::create(['professional_id' => $prof->id, 'amount_cents' => 4000]);
        $e->update(['commission_payout_id' => $payout->id]);
        $this->assertSame($payout->id, $e->fresh()->commission_payout_id);

        $this->expectException(DomainRuleViolation::class);
        $e->update(['amount_cents' => 1]);
    }

    public function test_preco_nulo_so_para_item_antigo_desconhecido(): void
    {
        $ag = Appointment::factory()->create();
        $ok = AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Service, 'name' => 'Servico removido', 'unit_price_cents' => null, 'price_source' => PriceSource::LegacyUnknown]);
        $this->assertNull($ok->total_cents);

        $comQtd = AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Product, 'name' => 'Pomada', 'quantity' => 3, 'unit_price_cents' => 3590, 'price_source' => PriceSource::CatalogAtBooking]);
        $this->assertSame(10770, $comQtd->total_cents);

        $this->expectException(DomainRuleViolation::class);
        AppointmentItem::create(['appointment_id' => $ag->id, 'item_type' => ItemType::Service, 'name' => 'Sem preco', 'unit_price_cents' => null, 'price_source' => PriceSource::CatalogAtBooking]);
    }

    public function test_transicoes_de_status(): void
    {
        $ag = Appointment::factory()->status(AppointmentStatus::Pending)->create();
        $ag->update(['status' => AppointmentStatus::Confirmed]);
        $ag->update(['status' => AppointmentStatus::Completed]);
        $this->assertTrue($ag->status->isFinal());

        $this->expectException(DomainRuleViolation::class);
        $ag->update(['status' => AppointmentStatus::Pending]);
    }

    public function test_matriz_de_transicoes(): void
    {
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::NoShow));
        $this->assertTrue(AppointmentStatus::NoShow->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Cancelled->blocksSlot());
        $this->assertTrue(AppointmentStatus::AwaitingPayment->blocksSlot());
    }

    public function test_agendamento_termina_depois_de_comecar(): void
    {
        $this->expectException(DomainRuleViolation::class);
        Appointment::factory()->create(['starts_at' => now(), 'ends_at' => now()]);
    }

    public function test_codigo_publico_gerado_sem_caracteres_ambiguos(): void
    {
        $code = Appointment::factory()->create()->code;
        $this->assertMatchesRegularExpression('/^AG-[2-9A-HJKMNP-Z]{6}$/', $code);
    }

    public function test_cupom_coerente(): void
    {
        $this->assertSame(1500, Coupon::factory()->create(['discount_type' => DiscountType::Fixed, 'percent_bp' => null, 'amount_cents' => 1500])->amount_cents);
        foreach ([['percent_bp' => 0], ['percent_bp' => 10001], ['percent_bp' => 1000, 'amount_cents' => 100], ['discount_type' => DiscountType::Fixed, 'percent_bp' => null, 'amount_cents' => 0]] as $ruim) {
            try {
                Coupon::factory()->create($ruim);
                $this->fail('Aceitou cupom incoerente: '.json_encode($ruim));
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nota_de_1_a_5_e_comissao_ate_100_por_cento(): void
    {
        try {
            Review::create(['rating' => 6]);
            $this->fail('Nota 6');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DomainRuleViolation::class);
        Professional::factory()->create(['commission_rate_bp' => 10001]);
    }

    public function test_servico_com_duracao_e_preco_validos(): void
    {
        $this->expectException(DomainRuleViolation::class);
        Service::factory()->create(['price_cents' => -1]);
    }

    public function test_configuracao_recusa_segredo(): void
    {
        Setting::create(['key' => 'site.titulo', 'value' => ['texto' => 'Barbearia']]);
        foreach ([['smtp' => ['password' => 'x']], ['api_key' => 'x'], ['gemini_keys' => 'x'], ['webhook_secret' => 'x']] as $segredo) {
            try {
                Setting::create(['key' => 'teste.'.uniqid(), 'value' => $segredo]);
                $this->fail('Aceitou segredo: '.json_encode(array_keys($segredo)));
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_usuario_ativo_precisa_de_email_ou_usuario(): void
    {
        $u = User::factory()->create(['email' => null, 'username' => 'Carlos ']);
        $this->assertSame('carlos', $u->username);

        $pendente = new User(['name' => 'Pendente']);
        $pendente->forceFill(['role' => StaffRole::Reception, 'is_active' => false, 'email' => null])->save();
        $this->assertNull($pendente->fresh()->username);

        $this->expectException(DomainRuleViolation::class);
        User::factory()->create(['email' => null, 'username' => null]);
    }

    public function test_auditoria_registra_mudancas_sem_senha_e_com_cpf_mascarado(): void
    {
        $c = Customer::factory()->create(['cpf' => '529.982.247-25']);
        $c->update(['name' => 'Nome Novo']);
        $logs = AuditLog::where('auditable_type', 'Customer')->where('auditable_id', $c->id)->get();
        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
        $this->assertArrayNotHasKey('password', $logs[0]->new_values);
        $this->assertSame('529.***.***-25', $logs[0]->new_values['cpf']);
        $this->assertSame(['name' => 'Nome Novo'], $logs[1]->new_values);

        $this->expectException(DomainRuleViolation::class);
        $logs[0]->update(['action' => 'forjado']);
    }

    public function test_banco_limpo_passa_na_verificacao_de_integridade(): void
    {
        Appointment::factory()->count(3)->create();
        $this->assertSame([], app(IntegrityChecker::class)->violations());
        $this->assertGreaterThanOrEqual(24, count(app(IntegrityChecker::class)->rules()));
    }
}

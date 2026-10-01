<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Enums\StockMovementKind;
use App\Modules\Catalog\Exceptions\StockRuleViolation;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Checkout\Enums\AttendanceSource;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\AttendanceService;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\Payment;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Pricing\Discount;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * As unicas regras do atendimento: abrir, iniciar, itens, desconto,
 * concluir (transacional e idempotente) e cancelar.
 */
class AttendanceServiceTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    protected function tearDown(): void
    {
        AttendanceService::$beforeFinish = null;
        parent::tearDown();
    }

    // --- Abrir -----------------------------------------------------------------------------------

    public function test_abre_do_agendamento_com_o_preco_combinado_e_o_profissional(): void
    {
        $ag = $this->todayAppointment();
        $this->corte->update(['price_cents' => 6000]); // mudou depois de agendar

        $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);

        $this->assertSame(AttendanceStatus::Open, $at->status);
        $this->assertSame(AttendanceSource::Appointment, $at->source);
        $this->assertSame($ag->id, $at->appointment_id);
        $this->assertSame(['João', 'Cliente Fictício', $this->cliente->id], [$at->professional_name, $at->customer_name, $at->customer_id]);
        $item = $at->items()->sole();
        $this->assertSame([5000, PriceSource::CatalogAtBooking], [$item->unit_price_cents, $item->price_source], 'vale o preço do agendamento');
        $this->assertSame('opened', $at->events()->sole()->type);
    }

    public function test_abrir_duas_vezes_devolve_o_mesmo_atendimento(): void
    {
        $ag = $this->todayAppointment();
        $a = $this->attendances()->openFromAppointment($ag, $this->recepcao);
        $b = $this->attendances()->openFromAppointment($ag, $this->recepcao);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_agendamento_cancelado_ou_de_outro_dia_nao_vira_atendimento(): void
    {
        $cancelado = $this->todayAppointment('11:00');
        $this->booking()->cancel($cancelado, Channel::Staff, $this->recepcao);
        $amanha = $this->book($this->terca, '10:00', channel: Channel::Staff);

        foreach ([[$cancelado, 'appointment_not_open'], [$amanha, 'appointment_not_today']] as [$ag, $motivo]) {
            try {
                $this->attendances()->openFromAppointment($ag, $this->recepcao);
                $this->fail('deveria recusar');
            } catch (CheckoutRuleViolation $e) {
                $this->assertSame($motivo, $e->reason);
            }
        }
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_agendamento_pendente_e_confirmado_ao_abrir(): void
    {
        BookingPolicy::save(['requires_confirmation' => true]);
        $ag = $this->book($this->segunda, '10:30', customer: $this->cliente);
        $this->assertSame(AppointmentStatus::Pending, $ag->status);

        $this->attendances()->openFromAppointment($ag, $this->recepcao);

        $this->assertSame(AppointmentStatus::Confirmed, $ag->fresh()?->status);
    }

    public function test_encaixe_sem_agendamento_com_nome_e_telefone(): void
    {
        $at = $this->attendances()->openWalkIn($this->corte, $this->joao, null, 'Visitante Fictício', '11 90000-0000', $this->recepcao);

        $this->assertSame(AttendanceSource::WalkIn, $at->source);
        $this->assertNull($at->appointment_id);
        $this->assertSame([5000, PriceSource::CatalogAtAttendance], [$at->items()->sole()->unit_price_cents, $at->items()->sole()->price_source]);
    }

    public function test_encaixe_exige_contato_e_servico_que_o_profissional_faz(): void
    {
        $outro = Service::factory()->create();

        foreach ([
            fn () => $this->attendances()->openWalkIn($this->corte, $this->joao, null, ' ', null, $this->recepcao),
            fn () => $this->attendances()->openWalkIn($outro, $this->joao, null, 'Visitante', null, $this->recepcao),
        ] as $n => $tentativa) {
            try {
                $tentativa();
                $this->fail("tentativa {$n} deveria falhar");
            } catch (CheckoutRuleViolation $e) {
                $this->assertContains($e->reason, ['contact_required', 'service_unavailable']);
            }
        }
        $this->assertSame(0, Attendance::query()->count());
    }

    // --- Estados ---------------------------------------------------------------------------------

    public function test_estados_e_transicoes(): void
    {
        $at = $this->attendances()->openFromAppointment($this->todayAppointment(), $this->recepcao);
        $this->openCash();

        try {
            $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
            $this->fail('concluir sem iniciar');
        } catch (CheckoutRuleViolation $e) {
            $this->assertSame('not_started', $e->reason);
        }

        $at = $this->attendances()->start($at, $this->recepcao);
        $this->assertSame(AttendanceStatus::InProgress, $at->status);
        $this->assertNotNull($at->started_at);

        $at = $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
        $this->assertSame(AttendanceStatus::Completed, $at->status);

        foreach ([
            fn () => $this->attendances()->start($at, $this->recepcao),
            fn () => $this->attendances()->cancel($at, 'Desistiu', $this->recepcao),
            fn () => $this->attendances()->addService($at, $this->barba, $this->recepcao),
            fn () => $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Cortesia', $this->recepcao),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('concluído não pode voltar nem mudar');
            } catch (CheckoutRuleViolation $e) {
                $this->assertSame('invalid_status', $e->reason);
            }
        }
    }

    public function test_concluido_nao_e_alterado_nem_por_fora_do_servico(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->expectException(DomainRuleViolation::class);
        $at->total_cents = 1;
        $at->save();
    }

    public function test_item_de_atendimento_concluido_e_historico(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->expectException(DomainRuleViolation::class);
        $at->items()->sole()->delete();
    }

    // --- Conclusao -------------------------------------------------------------------------------

    public function test_conclusao_completa_grava_tudo_e_conclui_o_agendamento(): void
    {
        $caixa = $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $this->attendances()->addConsumption($at, $this->lamina, 2, $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Cliente frequente', $this->recepcao);
        // Servicos 80,00; desconto 10% = 8,00 (nao incide na pomada); pomada 35,00 => 107,00.

        $at = $this->attendances()->complete($at, [
            new PaymentLine(PaymentMethod::Pix, 6000),
            new PaymentLine(PaymentMethod::CreditCard, 4700, 500),
        ], $this->key(), $this->recepcao);

        $this->assertSame([11500, 800, 10700, 500], [$at->subtotal_cents, $at->discount_cents, $at->total_cents, $at->tip_cents]);
        $this->assertSame(AppointmentStatus::Completed, $at->appointment?->status);
        $this->assertNotNull($at->appointment?->completed_at);

        $pagamentos = Payment::query()->where('attendance_id', $at->id)->orderBy('id')->get();
        $this->assertSame([6000, 4700], $pagamentos->pluck('amount_cents')->all());
        $this->assertTrue($pagamentos->every(fn (Payment $p) => $p->cash_session_id === $caixa->id));

        $mov = CashMovement::query()->where('cash_session_id', $caixa->id)->orderBy('id')->get();
        $this->assertSame([6000, 5200], $mov->pluck('amount_cents')->all(), 'caixa recebe valor + gorjeta');
        $this->assertTrue($mov->every(fn (CashMovement $m) => $m->type === CashMovementType::Payment && $m->payment_id !== null));

        $estoque = StockMovement::query()->where('attendance_id', $at->id)->orderBy('id')->get();
        $this->assertSame([[StockMovementKind::Sale, -1, 9], [StockMovementKind::Consumption, -2, 98]],
            $estoque->map(fn (StockMovement $m) => [$m->kind, $m->quantity, $m->balance_after])->all());

        $this->assertSame('completed', $at->events()->get()->last()?->type);
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'Attendance')->where('auditable_id', $at->id)->where('action', 'updated')->exists());
        $this->assertSame([], app(IntegrityChecker::class)->violations(), 'agendamento, atendimento, pagamento, caixa e estoque consistentes');
    }

    public function test_preco_profissional_e_cliente_ficam_congelados_depois_de_concluir(): void
    {
        $this->openCash();
        $at = $this->attendances()->complete($this->startedAttendance(), $this->pay(5000), $this->key(), $this->recepcao);

        $this->corte->update(['price_cents' => 9000, 'name' => 'Corte novo']);
        $this->joao->update(['display_name' => 'João Renomeado']);
        $this->cliente->update(['name' => 'Outro Nome']);

        $at = $at->fresh();
        $this->assertSame(5000, $at?->total_cents);
        $this->assertSame(['João', 'Cliente Fictício'], [$at?->professional_name, $at?->customer_name]);
        $this->assertSame(['Corte', 5000], [$at?->items()->sole()->name, $at?->items()->sole()->total_cents]);
    }

    public function test_registra_o_profissional_que_efetivamente_atendeu(): void
    {
        $pedro = Professional::factory()->create(['display_name' => 'Pedro']);
        $this->openCash();
        $at = $this->startedAttendance();

        $at = $this->attendances()->changeProfessional($at, $pedro, $this->recepcao);
        $at = $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);

        $this->assertSame([$pedro->id, 'Pedro'], [$at->professional_id, $at->professional_name]);
        $this->assertSame('João', $at->appointment?->professional_name, 'a reserva continua mostrando quem estava agendado');
    }

    public function test_pagamentos_precisam_fechar_exatamente_com_o_total(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();

        foreach ([4999, 5001] as $valor) {
            try {
                $this->attendances()->complete($at, $this->pay($valor), $this->key(), $this->recepcao);
                $this->fail('valor incorreto');
            } catch (CheckoutRuleViolation $e) {
                $this->assertSame('payment_mismatch', $e->reason);
            }
        }
        try {
            $this->attendances()->complete($at, [new PaymentLine(PaymentMethod::Unknown, 5000)], $this->key(), $this->recepcao);
            $this->fail('forma desconhecida');
        } catch (CheckoutRuleViolation $e) {
            $this->assertSame('invalid_payment', $e->reason);
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()?->status);
    }

    public function test_sem_caixa_aberto_nao_conclui_e_nada_fica_gravado(): void
    {
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);

        try {
            $this->attendances()->complete($at, $this->pay(8500), $this->key(), $this->recepcao);
            $this->fail('sem caixa');
        } catch (CashRuleViolation $e) {
            $this->assertSame('no_open_session', $e->reason);
        }

        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()?->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(10, $this->stock()->balance($this->pomada));
    }

    public function test_desconto_de_100_por_cento_conclui_sem_pagamento_e_sem_caixa(): void
    {
        $at = $this->startedAttendance();
        $this->attendances()->applyDiscount($at, Discount::percent(10000), 'Cortesia do dono', $this->recepcao);

        $at = $this->attendances()->complete($at, [], $this->key(), $this->recepcao);

        $this->assertSame([5000, 5000, 0], [$at->subtotal_cents, $at->discount_cents, $at->total_cents]);
        $this->assertSame(0, Payment::query()->count());
    }

    // --- Rollback, idempotencia --------------------------------------------------------------------

    public function test_falha_no_meio_desfaz_pagamento_caixa_estoque_e_agendamento(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 2, $this->recepcao);
        AttendanceService::$beforeFinish = fn () => throw new RuntimeException('falha simulada depois do pagamento');

        try {
            $this->attendances()->complete($at, $this->pay(12000), $this->key(), $this->recepcao);
            $this->fail('deveria falhar');
        } catch (RuntimeException $e) {
            $this->assertSame('falha simulada depois do pagamento', $e->getMessage());
        }

        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()?->status, 'atendimento não fica concluído pela metade');
        $this->assertSame(0, Payment::query()->count(), 'sem pagamento');
        $this->assertSame(0, CashMovement::query()->count(), 'caixa sem entrada');
        $this->assertSame(10, $this->stock()->balance($this->pomada), 'estoque não baixado');
        $this->assertSame(AppointmentStatus::Confirmed, $at->appointment?->fresh()?->status);
    }

    public function test_estoque_insuficiente_desfaz_a_conclusao_inteira(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addConsumption($at, $this->lamina, 5, $this->recepcao);
        $this->attendances()->addProduct($at, $this->pomada, 11, $this->recepcao);

        try {
            $this->attendances()->complete($at, $this->pay(5000 + 11 * 3500), $this->key(), $this->recepcao);
            $this->fail('estoque insuficiente');
        } catch (StockRuleViolation $e) {
            $this->assertSame('insufficient', $e->reason);
        }

        $this->assertSame(100, $this->stock()->balance($this->lamina), 'a lâmina também não foi baixada');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_duplo_clique_em_concluir_nao_duplica_nada(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $chave = $this->key();

        $a = $this->attendances()->complete($at, $this->pay(8500), $chave, $this->recepcao);
        $b = $this->attendances()->complete($at, $this->pay(8500), $chave, $this->recepcao);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::query()->count(), 'um pagamento');
        $this->assertSame(1, CashMovement::query()->count(), 'uma entrada no caixa');
        $this->assertSame(9, $this->stock()->balance($this->pomada), 'uma baixa');

        try {
            $this->attendances()->complete($at, $this->pay(8500), $this->key(), $this->recepcao);
            $this->fail('outra conclusão do mesmo atendimento');
        } catch (CheckoutRuleViolation $e) {
            $this->assertSame('invalid_status', $e->reason);
        }
        $this->assertSame(1, Payment::query()->count());
    }

    // --- Cancelar --------------------------------------------------------------------------------

    public function test_cancelar_antes_de_concluir_nao_mexe_em_estoque_nem_caixa(): void
    {
        $this->openCash();
        $at = $this->startedAttendance();
        $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao);
        $this->attendances()->addConsumption($at, $this->lamina, 1, $this->recepcao);

        $at = $this->attendances()->cancel($at, 'Cliente desistiu', $this->recepcao);

        $this->assertSame(AttendanceStatus::Cancelled, $at->status);
        $this->assertSame('Cliente desistiu', $at->cancellation_reason);
        $this->assertSame([10, 100], [$this->stock()->balance($this->pomada), $this->stock()->balance($this->lamina)]);
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame(AppointmentStatus::Confirmed, $at->appointment?->fresh()?->status, 'a equipe decide o que houve com a reserva');

        $novo = $this->attendances()->openFromAppointment($at->appointment, $this->recepcao);
        $this->assertNotSame($at->id, $novo->id, 'pode reabrir um atendimento novo');
    }

    public function test_cancelar_exige_motivo(): void
    {
        $at = $this->startedAttendance();

        $this->expectException(CheckoutRuleViolation::class);
        $this->attendances()->cancel($at, ' ', $this->recepcao);
    }

    // --- Itens e produtos ------------------------------------------------------------------------

    public function test_produto_inativo_ou_sem_preco_nao_entra_em_atendimento_novo(): void
    {
        $at = $this->startedAttendance();
        $this->pomada->update(['is_active' => false]);

        foreach ([
            [fn () => $this->attendances()->addProduct($at, $this->pomada, 1, $this->recepcao), 'product_inactive'],
            [fn () => $this->attendances()->addConsumption($at, $this->pomada, 1, $this->recepcao), 'product_inactive'],
            [fn () => $this->attendances()->addProduct($at, $this->lamina, 1, $this->recepcao), 'not_for_sale'],
            [fn () => $this->attendances()->addProduct($at, $this->lamina, 0, $this->recepcao), 'invalid_quantity'],
        ] as [$tentativa, $motivo]) {
            try {
                $tentativa();
                $this->fail($motivo);
            } catch (CheckoutRuleViolation $e) {
                $this->assertSame($motivo, $e->reason);
            }
        }
    }

    public function test_retirar_item_recalcula_o_desconto(): void
    {
        $at = $this->startedAttendance();
        $this->attendances()->addService($at, $this->barba, $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Promoção da casa', $this->recepcao);
        $this->assertSame(800, $at->discounts()->sole()->amount_cents);

        $barba = $at->items()->where('name', 'Barba')->sole();
        $this->attendances()->removeItem($at, $barba, $this->recepcao);

        $d = $at->discounts()->sole();
        $this->assertSame([5000, 500], [$d->base_cents, $d->amount_cents], 'base e valor antes/depois recalculados');
    }

    public function test_aplicar_desconto_de_novo_substitui_o_anterior(): void
    {
        $at = $this->startedAttendance();
        $this->attendances()->applyDiscount($at, Discount::percent(1000), 'Primeiro motivo', $this->recepcao);
        $this->attendances()->applyDiscount($at, Discount::fixed(700), 'Segundo motivo', $this->recepcao);

        $d = $at->discounts()->sole();
        $this->assertSame([700, 5000, 'Segundo motivo', $this->recepcao->id], [$d->amount_cents, $d->base_cents, $d->reason, $d->applied_by_user_id]);
    }

    public function test_desconto_exige_motivo(): void
    {
        $at = $this->startedAttendance();

        $this->expectException(CheckoutRuleViolation::class);
        $this->attendances()->applyDiscount($at, Discount::fixed(100), '', $this->recepcao);
    }

    public function test_item_de_outro_atendimento_nao_pode_ser_retirado(): void
    {
        $a = $this->startedAttendance();
        $b = $this->attendances()->openWalkIn($this->corte, $this->joao, null, 'Outro Visitante', null, $this->recepcao);

        $this->expectException(CheckoutRuleViolation::class);
        $this->attendances()->removeItem($a, $b->items()->sole(), $this->recepcao);
    }
}

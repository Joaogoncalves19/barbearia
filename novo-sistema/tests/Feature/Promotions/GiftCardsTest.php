<?php

namespace Tests\Feature\Promotions;

use App\Modules\Checkout\Exceptions\CheckoutRuleViolation;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Exceptions\CashRuleViolation;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\GiftCardStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PromotionFixtures;
use Tests\TestCase;

/**
 * Vale-presente como forma de pagamento (decisao do dono, vale-presente.md):
 * a venda entra no caixa; o uso e unico, no valor do vale ate o total; nao
 * entra na gaveta; a comissao e sobre o servico inteiro; o cancelamento
 * devolve pelo caixa; o estorno de pagamento com vale e bloqueado.
 */
class GiftCardsTest extends TestCase
{
    use PromotionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotions();
    }

    private function sell(int $cents = 3000, PaymentMethod $method = PaymentMethod::Pix, array $data = [], string $key = 'venda-1'): GiftCard
    {
        return $this->giftCards()->sell($cents, $method, ['purchaser_name' => 'Comprador Fictício', ...$data], $this->recepcao, $key);
    }

    private int $hora = 10;

    /** Atendimento iniciado do Corte (R$ 50,00), cada um num horario. */
    private function next(): Attendance
    {
        $ag = $this->bookToday($this->cliente, sprintf('%02d:00', $this->hora++));

        return $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);
    }

    private function payWith(GiftCard $vale, int $valeCents, int $resto = 0): Attendance
    {
        $at = $this->next();
        $linhas = [new PaymentLine(PaymentMethod::GiftCard, $valeCents, 0, $vale->code)];
        if ($resto > 0) {
            $linhas[] = new PaymentLine(PaymentMethod::Pix, $resto);
        }

        return $this->attendances()->complete($at, $linhas, $this->key(), $this->recepcao);
    }

    public function test_venda_entra_no_caixa_e_e_idempotente(): void
    {
        $vale = $this->sell(3000, PaymentMethod::Cash, ['expires_on' => '2027-10-05']);
        $denovo = $this->sell(3000, PaymentMethod::Cash, ['expires_on' => '2027-10-05']);

        $this->assertSame($vale->id, $denovo->id);
        $this->assertMatchesRegularExpression('/^PRESENTE-[A-Z2-9]{8}$/', $vale->code);
        $mov = CashMovement::query()->where('type', CashMovementType::GiftCardSale)->sole();
        $this->assertSame([3000, PaymentMethod::Cash, $vale->id], [$mov->amount_cents, $mov->method, $mov->gift_card_id]);
        $this->assertSame(10000 + 3000, $this->cash()->expectedCash($this->cash()->current()), 'dinheiro da venda na gaveta');
        $this->assertSame(1, AuditLog::query()->where('action', 'gift_card.sold')->count());
    }

    public function test_venda_invalida_nao_grava_nada(): void
    {
        foreach ([[0, PaymentMethod::Pix, []], [3000, PaymentMethod::GiftCard, []], [3000, PaymentMethod::Pix, ['expires_on' => '2026-10-04']], [3000, PaymentMethod::Pix, ['purchaser_email' => 'invalido']]] as $i => [$v, $m, $d]) {
            try {
                $this->sell($v, $m, $d, 'k'.$i);
                $this->fail('deveria recusar');
            } catch (PromotionRejected) {
            }
        }
        $this->assertSame([0, 0], [GiftCard::query()->count(), CashMovement::query()->where('type', CashMovementType::GiftCardSale)->count()]);
    }

    public function test_uso_unico_paga_o_atendimento_sem_entrar_na_gaveta_e_comissao_no_valor_cheio(): void
    {
        $dono = User::factory()->owner()->create();
        app(CommissionRules::class)->set(CommissionTarget::Service, null, null, CommissionRuleType::Percent, 4000, null, null, $dono);
        $vale = $this->sell(3000);
        $gaveta = $this->cash()->expectedCash($this->cash()->current());

        $at = $this->payWith($vale, 3000, 2000);

        $pg = Payment::query()->where('method', PaymentMethod::GiftCard)->sole();
        $this->assertSame([$vale->id, null, 3000], [$pg->gift_card_id, $pg->cash_session_id, $pg->amount_cents]);
        $vale->refresh();
        $this->assertSame([GiftCardStatus::Redeemed, $at->id], [$vale->status, $vale->redeemed_attendance_id]);
        $this->assertSame($gaveta, $this->cash()->expectedCash($this->cash()->current()), 'o Pix não entra na gaveta e o vale também não');
        $this->assertSame(2000, CommissionEntry::query()->where('attendance_id', $at->id)->sum('amount_cents'), '40% de R$ 50,00');
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        try {
            $this->payWith($vale, 3000, 2000);
            $this->fail('vale usado não paga de novo');
        } catch (CheckoutRuleViolation $e) {
            $this->assertStringContainsString('usado', $e->getMessage());
        }
    }

    public function test_valor_do_vale_tem_que_ser_o_permitido(): void
    {
        $vale = $this->sell(8000);

        foreach ([[2000, 3000], [8000, 0]] as [$v, $resto]) {
            try {
                $this->payWith($vale, $v, $resto);
                $this->fail('valor errado');
            } catch (CheckoutRuleViolation $e) {
                $this->assertStringContainsString('R$ 50,00', $e->getMessage(), 'vale de R$ 80,00 paga R$ 50,00 do corte');
            }
        }
        $this->assertSame(GiftCardStatus::Available, $vale->fresh()?->status);

        $this->payWith($vale, 5000);
        $this->assertSame(GiftCardStatus::Redeemed, $vale->fresh()?->status, 'o que sobra não vira saldo (uso único, R-13)');
    }

    public function test_vale_vencido_cancelado_ou_inexistente_e_recusado_e_gorjeta_nao(): void
    {
        $vencido = $this->sell(3000, PaymentMethod::Pix, ['expires_on' => '2026-10-05']);
        DB::table('gift_cards')->where('id', $vencido->id)->update(['expires_on' => '2026-10-04']); // venceu ontem

        foreach (['PRESENTE-NAOEXISTE' => 'não encontrado', $vencido->code => 'vencido'] as $codigo => $trecho) {
            try {
                $this->attendances()->complete($this->next(), [new PaymentLine(PaymentMethod::GiftCard, 3000, 0, $codigo), new PaymentLine(PaymentMethod::Pix, 2000)], $this->key(), $this->recepcao);
                $this->fail($trecho);
            } catch (CheckoutRuleViolation $e) {
                $this->assertStringContainsString($trecho, mb_strtolower($e->getMessage()));
            }
        }

        $this->expectException(CheckoutRuleViolation::class);
        $this->attendances()->complete($this->next(), [new PaymentLine(PaymentMethod::GiftCard, 3000, 500, $vencido->code)], $this->key(), $this->recepcao);
    }

    public function test_cancelamento_devolve_pelo_caixa_e_estorno_de_vale_e_bloqueado(): void
    {
        $vale = $this->sell(3000, PaymentMethod::Cash, [], 'v1');
        $this->giftCards()->cancel($vale, 'Desistência', $this->recepcao);

        $dev = CashMovement::query()->where('type', CashMovementType::GiftCardRefund)->sole();
        $this->assertSame([-3000, PaymentMethod::Cash], [$dev->amount_cents, $dev->method]);
        $this->assertSame(10000, $this->cash()->expectedCash($this->cash()->current()));
        $this->assertSame(GiftCardStatus::Cancelled, $vale->fresh()?->status);
        try {
            $this->giftCards()->cancel($vale, 'De novo', $this->recepcao);
            $this->fail('cancela uma vez');
        } catch (PromotionRejected $e) {
            $this->assertSame('already_cancelled', $e->reason);
        }

        $outro = $this->sell(3000, PaymentMethod::Pix, [], 'v2');
        $this->payWith($outro, 3000, 2000);
        $pg = Payment::query()->where('method', PaymentMethod::GiftCard)->sole();
        try {
            $this->corrections()->refund($pg, 3000, 'Reclamação', $this->recepcao, 'est-1');
            $this->fail('estorno de vale');
        } catch (CashRuleViolation $e) {
            $this->assertStringContainsString('vale-presente', $e->getMessage());
        }
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_vale_e_imutavel_fora_do_servico(): void
    {
        $vale = $this->sell();

        $this->expectException(DomainRuleViolation::class);
        $vale->update(['amount_cents' => 999999]);
    }
}

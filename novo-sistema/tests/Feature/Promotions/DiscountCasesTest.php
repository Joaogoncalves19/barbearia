<?php

namespace Tests\Feature\Promotions;

use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Models\CouponRedemption;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Pricing\PromotionRequest;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PromotionFixtures;
use Tests\TestCase;

/**
 * TABELA DE CASOS DE DESCONTO (criterio de aceite da Fase 8): todas as
 * combinacoes de cupom (nenhum, 10%, R$ 15,00, R$ 60,00), pontos (sim/nao),
 * aniversario (sim/nao) e indicacao (sim/nao) sobre um Corte de R$ 50,00.
 * Em cada caso:
 *   1. o desconto escolhido e o MAIOR (R-10), com o desempate documentado;
 *   2. orcamento exibido (previa do motor) = valor gravado no agendamento =
 *      valor cobrado na conclusao do atendimento;
 *   3. so o cupom/pontos escolhido e consumido; o resto nao e reservado.
 *
 * Valores: aniversario 15% = 7,50; indicacao 10% = 5,00; cupom 10% = 5,00;
 * cupom R$ 15,00; cupom R$ 60,00 (limitado a 50,00); pontos = 50% do
 * servico mais barato = 25,00.
 */
class DiscountCasesTest extends TestCase
{
    use PromotionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotions();
        $this->policy(['birthday_enabled' => true, 'referral_enabled' => true]);
        $this->coupon('DEZ', 'percent', 1000);
        $this->coupon('QUINZE', 'fixed', 1500);
        $this->coupon('SESSENTA', 'fixed', 6000);
    }

    /**
     * @return list<array{0: ?string, 1: bool, 2: bool, 3: bool}>
     */
    private function combinations(): array
    {
        $casos = [];
        foreach ([null, 'DEZ', 'QUINZE', 'SESSENTA'] as $cupom) {
            foreach ([false, true] as $pontos) {
                foreach ([false, true] as $aniversario) {
                    foreach ([false, true] as $indicacao) {
                        $casos[] = [$cupom, $pontos, $aniversario, $indicacao];
                    }
                }
            }
        }

        return $casos;
    }

    /**
     * O que a regra diz que deve acontecer: [tipo escolhido, desconto].
     *
     * @return array{0: ?AdjustmentKind, 1: int}
     */
    private function expected(?string $cupom, bool $pontos, bool $aniversario, bool $indicacao): array
    {
        // Ordem = desempate: aniversario, indicacao, cupom, pontos.
        $candidatos = [];
        if ($aniversario) {
            $candidatos[] = [AdjustmentKind::Birthday, 750];
        }
        if ($indicacao) {
            $candidatos[] = [AdjustmentKind::Referral, 500];
        }
        if ($cupom !== null) {
            $candidatos[] = [AdjustmentKind::Coupon, ['DEZ' => 500, 'QUINZE' => 1500, 'SESSENTA' => 5000][$cupom]];
        }
        if ($pontos) {
            $candidatos[] = [AdjustmentKind::Loyalty, 2500];
        }
        $melhor = [null, 0];
        foreach ($candidatos as $c) {
            if ($c[1] > $melhor[1]) {
                $melhor = $c;
            }
        }

        return $melhor;
    }

    public function test_todas_as_combinacoes_orcamento_igual_gravado_igual_cobrado(): void
    {
        $indicador = Customer::factory()->create(['name' => 'Indicador Fictício']);
        $casos = $this->combinations();
        $this->assertCount(32, $casos);

        foreach ($casos as $n => [$cupom, $pontos, $aniversario, $indicacao]) {
            $nome = 'caso '.$n.': cupom='.($cupom ?? '-').' pontos='.($pontos ? 's' : 'n').' aniversario='.($aniversario ? 's' : 'n').' indicacao='.($indicacao ? 's' : 'n');
            [$tipo, $desconto] = $this->expected($cupom, $pontos, $aniversario, $indicacao);

            $cliente = Customer::factory()->create([
                'birth_date' => $aniversario ? '1990-10-20' : '1990-03-15',
            ]);
            if ($indicacao) {
                $cliente->forceFill(['referred_by_customer_id' => $indicador->id])->save();
            }
            if ($pontos) {
                $this->givePoints($cliente, 10);
            }
            $pro = Professional::factory()->create();
            $pro->services()->attach($this->corte->id);
            $pedido = new PromotionRequest($cupom, $pontos);

            // 1. Previa (o que a tela mostra).
            $previa = $this->engine()->quote($cliente, [['total' => 5000, 'discountable' => true, 'unit' => 5000]], $this->segunda, $pedido);
            $this->assertSame([], $previa->problems, $nome);
            $this->assertSame($tipo, $previa->chosen?->kind, $nome.' (escolhido)');
            $this->assertSame(5000 - $desconto, $previa->totalCents(), $nome.' (previa)');

            // 2. Gravado no agendamento (com o total que a pessoa viu).
            $ag = $this->bookToday($cliente, '10:00', $pedido, $pro, $previa->totalCents());
            $this->assertSame(5000 - $desconto, $ag->total_cents, $nome.' (agendamento)');
            $this->assertSame($tipo === null ? 0 : 1, $ag->adjustments()->count(), $nome.' (um desconto so)');

            // 3. Cobrado na conclusao.
            $at = $this->attendances()->openFromAppointment($ag, $this->recepcao);
            $at = $this->attendances()->start($at, $this->recepcao);
            $pagamentos = $desconto < 5000 ? [new PaymentLine(PaymentMethod::Pix, 5000 - $desconto)] : []; // total zero: conclui sem pagamento
            $at = $this->attendances()->complete($at, $pagamentos, $this->key(), $this->recepcao);
            $this->assertSame([5000, $desconto, 5000 - $desconto], [$at->subtotal_cents, $at->discount_cents, $at->total_cents], $nome.' (cobrado)');

            // So o escolhido e consumido.
            $this->assertSame($tipo === AdjustmentKind::Coupon ? 1 : 0, CouponRedemption::query()->where('customer_id', $cliente->id)->where('status', RedemptionStatus::Redeemed)->count(), $nome.' (cupom usado)');
            $this->assertSame(0, CouponRedemption::query()->where('customer_id', $cliente->id)->where('status', RedemptionStatus::Reserved)->count(), $nome.' (sem reserva pendente)');
            $this->assertSame($tipo === AdjustmentKind::Loyalty ? 1 : 0, LoyaltyRedemption::query()->where('customer_id', $cliente->id)->where('status', RedemptionStatus::Redeemed)->count(), $nome.' (pontos usados)');
            $saldoEsperado = ($pontos ? 10 : 0) - ($tipo === AdjustmentKind::Loyalty ? 10 : 0) + 1; // +1 ponto ganho na visita
            $this->assertSame($saldoEsperado, $this->loyalty()->balance($cliente), $nome.' (saldo de pontos)');
        }

        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_aviso_quando_o_pedido_perde_para_um_desconto_maior(): void
    {
        $cliente = Customer::factory()->create(['birth_date' => '1990-10-20']);
        $q = $this->engine()->quote($cliente, [['total' => 5000, 'discountable' => true, 'unit' => 5000]], $this->segunda, new PromotionRequest('DEZ'));

        $this->assertSame(AdjustmentKind::Birthday, $q->chosen?->kind);
        $this->assertStringContainsString('Cupom DEZ', $q->notes[0] ?? '');
        $this->assertStringContainsString('só um desconto', $q->notes[0] ?? '');
    }

    public function test_desconto_nunca_incide_em_produto_nem_deixa_total_negativo(): void
    {
        $cliente = Customer::factory()->create();
        $linhas = [['total' => 5000, 'discountable' => true, 'unit' => 5000], ['total' => 3500, 'discountable' => false, 'unit' => 3500]];

        $q = $this->engine()->quote($cliente, $linhas, $this->segunda, new PromotionRequest('SESSENTA'));

        $this->assertSame([5000, 3500], [$q->chosen?->amountCents, $q->totalCents()], 'R$ 60,00 limitado aos R$ 50,00 de serviço; a pomada é cobrada inteira');
    }
}

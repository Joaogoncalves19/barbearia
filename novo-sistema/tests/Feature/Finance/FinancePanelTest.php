<?php

namespace Tests\Feature\Finance;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Comissao e repasse pelo painel: permissao especifica por acao (ver,
 * configurar, corrigir, pagar, estornar, historico), extrato so do proprio
 * (alheio = 404), requisicao adulterada e repetida, fluxo completo via HTTP.
 */
class FinancePanelTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    private User $barbeiroJoao;

    private User $gerente;

    private Professional $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->barbeiroJoao = User::factory()->role(StaffRole::Professional)->create();
        $this->joao->update(['user_id' => $this->barbeiroJoao->id]);
        $this->maria = Professional::factory()->create(['display_name' => 'Maria']);
        $this->gerente = User::factory()->manager()->create();
        $this->percent(4000, $this->joao);
    }

    private function as(User $u): static
    {
        return $this->actingAs($u, 'web');
    }

    public function test_quem_ve_o_que(): void
    {
        $at = $this->completedAttendance();
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        $daMaria = CommissionPayout::query()->create(['professional_id' => $this->maria->id, 'amount_cents' => 100, 'snapshot' => ['comissoes' => [], 'gorjetas' => [], 'vales' => []], 'commission_cents' => 100, 'tip_cents' => 0, 'advances_cents' => 0]);

        $rotas = [
            'index' => route('panel.commissions.index'),
            'regras' => route('panel.commission-rules.index'),
            'historico' => route('panel.commissions.history'),
            'repasses' => route('panel.payouts.index'),
            'extrato do João' => route('panel.commissions.show', $this->joao),
            'extrato da Maria' => route('panel.commissions.show', $this->maria),
            'novo repasse' => route('panel.payouts.create', $this->joao),
            'repasse do João' => route('panel.payouts.show', $p),
            'repasse da Maria' => route('panel.payouts.show', $daMaria),
        ];
        $esperado = [
            'dono' => [$this->dono, [200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'gerente' => [$this->gerente, [200, 403, 200, 200, 200, 200, 403, 200, 200]],
            'financeiro' => [$this->financeiro, [200, 403, 200, 200, 200, 200, 200, 200, 200]],
            'recepção' => [$this->recepcao, [403, 403, 403, 403, 404, 404, 403, 404, 404]],
            'profissional' => [$this->barbeiroJoao, [403, 403, 403, 403, 200, 404, 403, 200, 404]],
        ];
        $this->recepcao->forceFill(['role' => StaffRole::Reception])->save();

        foreach ($esperado as $papel => [$u, $codigos]) {
            $obtido = [];
            foreach (array_values($rotas) as $url) {
                $obtido[] = $this->as($u)->get($url)->status();
            }
            $this->assertSame($codigos, $obtido, "{$papel}: ".implode(', ', array_keys($rotas)));
        }

        $this->as($this->barbeiroJoao)->get(route('panel.commissions.mine'))->assertRedirect(route('panel.commissions.show', $this->joao));
        $this->as($this->barbeiroJoao)->get(route('panel.commissions.show', $this->joao))
            ->assertSee($at->code)->assertSee('R$ 20,00')->assertDontSee('Lançar vale')->assertDontSee('Registrar ajuste');
    }

    public function test_quem_pode_mudar_o_que(): void
    {
        $this->completedAttendance();
        $regra = ['target' => 'service', 'professional_id' => $this->joao->id, 'type' => 'percent', 'value' => '50'];
        $ajuste = ['request_key' => (string) Str::uuid(), 'ledger' => 'commission', 'direction' => 'credit', 'amount' => '5,00', 'reason' => 'Bônus'];
        $vale = ['request_key' => (string) Str::uuid(), 'amount' => '10,00', 'method' => 'pix', 'description' => 'Vale'];
        $repasse = ['request_key' => (string) Str::uuid(), 'method' => 'pix'];

        foreach ([$this->gerente, $this->recepcao, $this->barbeiroJoao] as $u) {
            $this->as($u)->post(route('panel.commission-rules.store'), $regra)->assertForbidden();
            $this->as($u)->post(route('panel.commissions.adjust', $this->joao), $ajuste)->assertForbidden();
            $this->as($u)->post(route('panel.advances.store', $this->joao), $vale)->assertForbidden();
            $this->as($u)->post(route('panel.payouts.store', $this->joao), $repasse)->assertForbidden();
        }
        $this->as($this->financeiro)->post(route('panel.commission-rules.store'), $regra)->assertForbidden();

        $this->assertSame(1, CommissionRule::query()->count());
        $this->assertSame([0, 0, 0], [Advance::query()->count(), CommissionPayout::query()->count(), CommissionEntry::query()->where('kind', 'adjustment')->count()]);
    }

    public function test_dono_configura_regra_pelo_painel(): void
    {
        $this->as($this->dono)->post(route('panel.commission-rules.store'), [
            'target' => 'service', 'professional_id' => $this->joao->id, 'service_id' => $this->barba->id, 'type' => 'percent', 'value' => '37,5', 'reason' => 'Barba paga mais',
        ])->assertSessionHasNoErrors();
        $r = CommissionRule::query()->where('service_id', $this->barba->id)->sole();
        $this->assertSame([3750, 'Barba paga mais', $this->dono->id], [$r->rate_bp, $r->reason, $r->created_by_user_id]);

        $this->as($this->dono)->post(route('panel.commission-rules.store'), ['target' => 'service', 'type' => 'percent', 'value' => '150'])->assertSessionHasErrors('rule');
        $this->as($this->dono)->post(route('panel.commission-rules.store'), ['target' => 'service', 'type' => 'percent', 'value' => 'abc'])->assertSessionHasErrors('value');
        $this->as($this->dono)->post(route('panel.commission-rules.store'), ['target' => 'hack', 'type' => 'percent', 'value' => '10'])->assertSessionHasErrors('target');

        $this->as($this->dono)->post(route('panel.commission-rules.clear'), ['rule_id' => $r->id])->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()?->current_scope);
        $this->as($this->dono)->get(route('panel.commissions.history'))->assertOk()->assertSee('Barba paga mais');
    }

    public function test_fluxo_completo_pelo_painel_ate_o_repasse(): void
    {
        $at = $this->startedAttendance();
        $this->as($this->recepcao)->post(route('panel.attendances.complete', $at), [
            'completion_key' => $this->key(),
            'payments' => [['method' => 'pix', 'amount' => '50,00', 'tip' => '6,00']],
        ])->assertSessionHasNoErrors();
        $at = Attendance::query()->findOrFail($at->id);

        $this->as($this->financeiro)->get(route('panel.commissions.index'))->assertOk()->assertSee('João')->assertSee('R$ 26,00');

        $chave = (string) Str::uuid();
        $this->as($this->financeiro)->post(route('panel.advances.store', $this->joao), ['request_key' => $chave, 'amount' => '5,00', 'method' => 'cash', 'description' => 'Vale do almoço'])->assertSessionHasNoErrors();
        $this->as($this->financeiro)->post(route('panel.advances.store', $this->joao), ['request_key' => $chave, 'amount' => '5,00', 'method' => 'cash', 'description' => 'Vale do almoço'])->assertSessionHasNoErrors();
        $this->assertSame(1, Advance::query()->count(), 'mesmo envio, um vale');

        $this->as($this->financeiro)->get(route('panel.payouts.create', $this->joao))->assertOk()->assertSee('R$ 21,00');
        $this->as($this->financeiro)->post(route('panel.payouts.store', $this->joao), ['request_key' => (string) Str::uuid(), 'method' => 'credit_card'])->assertSessionHasErrors('method');

        $chave = (string) Str::uuid();
        $r = $this->as($this->financeiro)->post(route('panel.payouts.store', $this->joao), ['request_key' => $chave, 'method' => 'cash']);
        $p = CommissionPayout::query()->sole();
        $r->assertRedirect(route('panel.payouts.show', $p));
        $this->as($this->financeiro)->post(route('panel.payouts.store', $this->joao), ['request_key' => $chave, 'method' => 'cash']);
        $this->assertSame(1, CommissionPayout::query()->count(), 'duplo clique não paga duas vezes');
        $this->assertSame([2000, 600, 500, 2100], [$p->commission_cents, $p->tip_cents, $p->advances_cents, $p->amount_cents]);

        $this->as($this->barbeiroJoao)->get(route('panel.payouts.show', $p))->assertOk()->assertSee('R$ 21,00')->assertDontSee('Estornar repasse');
        $this->as($this->financeiro)->post(route('panel.payouts.reverse', $p), ['request_key' => (string) Str::uuid(), 'reason' => 'Pago errado'])->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()?->isReversed());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_estorno_pelo_painel_informa_a_parte_da_gorjeta(): void
    {
        $at = $this->completedAttendance([new PaymentLine(PaymentMethod::Pix, 5000, 500)]);
        $pg = $at->payments()->sole();

        $this->as($this->dono)->get(route('panel.attendances.show', $at))->assertOk()->assertSee('Quanto disso é gorjeta');
        $this->as($this->dono)->post(route('panel.attendances.refund', [$at, $pg]), [
            'request_key' => (string) Str::uuid(), 'refund_amount' => '10,00', 'refund_tip' => '20,00', 'refund_reason' => 'Teste',
        ])->assertSessionHasErrors('refund');
        $this->as($this->dono)->post(route('panel.attendances.refund', [$at, $pg]), [
            'request_key' => (string) Str::uuid(), 'refund_amount' => '10,00', 'refund_tip' => '5,00', 'refund_reason' => 'Cliente insatisfeito',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-500, (int) TipEntry::query()->where('kind', 'refund')->sum('amount_cents'));
        $this->assertSame(-200, (int) CommissionEntry::query()->where('kind', 'refund')->sum('amount_cents'), '5,00 de 50,00 = 10% de 20,00');
    }

    public function test_ajuste_pelo_painel_valida_atendimento(): void
    {
        $this->as($this->financeiro)->post(route('panel.commissions.adjust', $this->joao), [
            'request_key' => (string) Str::uuid(), 'ledger' => 'tip', 'direction' => 'debit', 'amount' => '3,00', 'reason' => 'Gorjeta lançada a mais', 'attendance_code' => 'AT-NAOEXISTE',
        ])->assertSessionHasErrors('attendance_code');
        $this->as($this->financeiro)->post(route('panel.commissions.adjust', $this->joao), [
            'request_key' => (string) Str::uuid(), 'ledger' => 'tip', 'direction' => 'debit', 'amount' => '3,00', 'reason' => 'Gorjeta lançada a mais',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-300, TipEntry::query()->sole()->amount_cents);
    }

    public function test_telas_novas_sem_estilo_ou_handler_inline(): void
    {
        $at = $this->completedAttendance();
        $p = $this->payouts()->pay($this->joao, PaymentMethod::Pix, null, $this->financeiro, $this->key());
        foreach ([route('panel.commissions.index'), route('panel.commissions.show', $this->joao), route('panel.commission-rules.index'), route('panel.commissions.history'),
            route('panel.payouts.index'), route('panel.payouts.create', $this->joao), route('panel.payouts.show', $p)] as $url) {
            $html = $this->as($this->dono)->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\sstyle="|\son(click|change|submit|load|input)=/i', (string) $html, $url);
        }
        $this->assertNotNull($at);
    }
}

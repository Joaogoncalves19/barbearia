<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanVersion;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Planos (planos.md): so o proprietario configura; preco e servicos em
 * versoes imutaveis; quem ja assina fica na versao contratada; plano
 * fechado nao aceita adesao. Tambem: o verificador acusa gravacoes erradas.
 */
class PlansTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        $this->dono = User::factory()->owner()->create();
    }

    public function test_criar_plano_e_nova_versao_pelo_painel(): void
    {
        $this->actingAs($this->dono)->post(route('panel.plans.store'), ['name' => 'Clube Completo', 'price' => '149,90', 'services' => [$this->corte->id, $this->barba->id]])
            ->assertSessionHasNoErrors();
        $plano = Plan::query()->where('name', 'Clube Completo')->sole();
        $this->assertSame([14990, [$this->corte->id, $this->barba->id]], [$plano->currentVersion?->price_cents, $plano->currentVersion?->serviceIds()]);

        $this->actingAs($this->dono)->post(route('panel.plans.versions.store', $plano), ['price' => '159,90', 'services' => [$this->corte->id, $this->barba->id], 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->dono)->post(route('panel.plans.versions.store', $plano), ['price' => '149,90', 'services' => [$this->corte->id, $this->barba->id], 'reason' => 'Nada'])->assertSessionHasErrors('plan');
        $this->actingAs($this->dono)->post(route('panel.plans.versions.store', $plano), ['price' => '159,90', 'services' => [$this->corte->id, $this->barba->id], 'reason' => 'Reajuste anual'])->assertSessionHasNoErrors();

        $plano->refresh();
        $this->assertSame([2, 15990], [$plano->currentVersion?->version, $plano->currentVersion?->price_cents]);
        $this->assertNotNull(PlanVersion::query()->where('plan_id', $plano->id)->where('version', 1)->value('ends_at'));
        $this->assertSame(['plan.created', 'plan.versioned'], AuditLog::query()->whereIn('action', ['plan.created', 'plan.versioned'])->orderBy('id')->pluck('action')->all());
        $this->actingAs($this->dono)->get(route('panel.plans.edit', $plano))->assertOk()->assertSee('Reajuste anual')->assertSee('R$ 159,90');
    }

    public function test_plano_invalido_e_recusado(): void
    {
        $this->actingAs($this->dono)->post(route('panel.plans.store'), ['name' => 'Sem serviço', 'price' => '10,00', 'services' => []])->assertSessionHasErrors('services');
        $this->actingAs($this->dono)->post(route('panel.plans.store'), ['name' => 'Preço zero', 'price' => '0', 'services' => [$this->corte->id]])->assertSessionHasErrors('price');
        $this->assertSame(1, Plan::query()->count());
    }

    public function test_versao_e_imutavel_e_assinatura_nao_troca_de_versao(): void
    {
        $v = $this->clube->currentVersion;
        $this->assertNotNull($v);
        try {
            $v->update(['price_cents' => 1]);
            $this->fail('versão é histórico');
        } catch (DomainRuleViolation) {
        }

        $s = $this->activeSubscription($this->cliente);
        $this->expectException(DomainRuleViolation::class);
        $s->update(['plan_version_id' => PlanVersion::query()->max('id') + 1]);
    }

    public function test_plano_fechado_nao_aceita_adesao(): void
    {
        $this->actingAs($this->dono)->post(route('panel.plans.status', $this->clube), ['active' => '0'])->assertSessionHasNoErrors();
        $this->fakeStripe();

        $this->expectException(SubscriptionRuleViolation::class);
        $this->checkout()->start($this->cliente, $this->clube->refresh(), SubscriptionOrigin::Panel, null, $this->recepcao);
    }

    public function test_assinatura_e_pagamento_sao_historico(): void
    {
        $s = $this->activeSubscription($this->cliente);
        try {
            $s->delete();
            $this->fail('assinatura não se apaga');
        } catch (DomainRuleViolation) {
        }
        try {
            $s->update(['gateway_subscription_id' => 'sub_OUTRO']);
            $this->fail('ID do Stripe não é substituído');
        } catch (DomainRuleViolation) {
        }
        $this->expectException(DomainRuleViolation::class);
        SubscriptionPayment::query()->sole()->update(['amount_cents' => 1]);
    }

    public function test_transicao_direta_proibida_no_model(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $s->update(['status' => SubscriptionStatus::Cancelled]);

        $this->expectException(DomainRuleViolation::class);
        $s->refresh()->update(['status' => SubscriptionStatus::Active]);
    }

    public function test_verificador_acusa_gravacao_direta_errada(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $p = SubscriptionPayment::query()->sole();
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        DB::table('plan_versions')->update(['current_plan_id' => null, 'ends_at' => null]);
        DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'pending', 'active_customer_id' => $s->customer_id]);
        DB::table('subscription_refunds')->insert(['subscription_payment_id' => $p->id, 'customer_id' => $p->customer_id, 'amount_cents' => 99999, 'status' => 'succeeded', 'source' => 'staff', 'created_at' => now()]);
        DB::table('gateway_events')->insert(['gateway' => 'stripe', 'event_id' => 'evt_ruim', 'status' => 'failed', 'last_error' => null]);

        $v = app(IntegrityChecker::class)->violations();
        foreach (['R43_plano_versao', 'R44_assinatura_direito', 'R45_pagamento_assinatura', 'R46_evento_gateway'] as $regra) {
            $this->assertArrayHasKey($regra, $v, $regra);
        }
    }
}

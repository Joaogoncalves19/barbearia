<?php

namespace Tests\Feature\Data;

use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Customers\Services\DuplicateCustomerFinder;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_desconhecido_nao_autoriza_marketing(): void
    {
        $c = Customer::factory()->create();
        $this->assertSame(MarketingConsent::Unknown, $c->marketing_email_consent);
        $this->assertFalse($c->canReceiveMarketingEmail());
    }

    public function test_revogacao_vale_para_o_cliente_e_para_o_email_mesmo_sem_cadastro(): void
    {
        $servico = app(MarketingConsentService::class);
        $c = Customer::factory()->create(['email' => 'ana@exemplo.test']);
        $servico->grant($c, 'customer');
        $this->assertTrue($c->fresh()->canReceiveMarketingEmail());

        $servico->revoke($c, null, 'unsubscribe_link');
        $servico->revoke(null, 'Sem.Cadastro@Exemplo.test', 'unsubscribe_link');

        $this->assertFalse($c->fresh()->canReceiveMarketingEmail());
        $this->assertTrue(EmailSuppression::isSuppressed('ana@exemplo.test'));
        $this->assertTrue(EmailSuppression::isSuppressed('sem.cadastro@exemplo.test'));
        $this->assertSame(['granted', 'revoked', 'revoked'], ConsentRecord::orderBy('id')->pluck('action')->map->value->all());

        // Trocar de e-mail nao "limpa" a revogacao do cliente.
        $c->update(['email' => 'ana.nova@exemplo.test']);
        $this->assertFalse($c->fresh()->canReceiveMarketingEmail());
    }

    public function test_supressao_prevalece_sobre_consentimento(): void
    {
        $c = Customer::factory()->create(['email' => 'bia@exemplo.test']);
        EmailSuppression::create(['email' => 'bia@exemplo.test', 'reason' => 'bounce']);
        app(MarketingConsentService::class)->grant($c, 'customer');
        $this->assertTrue(EmailSuppression::isSuppressed('bia@exemplo.test'), 'aceite nao remove supressao por bounce');
        $this->assertFalse($c->fresh()->canReceiveMarketingEmail());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_detecta_duplicidade_sem_mesclar(): void
    {
        $a = Customer::factory()->create(['email' => 'dup@exemplo.test', 'phone' => '(11) 95555-0000']);
        $conflitos = app(DuplicateCustomerFinder::class)->conflicts(' DUP@exemplo.test', '+55 11 95555 0000', null);
        $this->assertSame(['email' => $a->id, 'phone' => $a->id], $conflitos);
        $this->assertSame([], app(DuplicateCustomerFinder::class)->conflicts('dup@exemplo.test', null, null, $a->id));
        $this->assertSame(1, Customer::count());
    }
}

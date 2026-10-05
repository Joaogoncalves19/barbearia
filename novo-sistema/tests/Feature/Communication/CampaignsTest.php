<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Communication\Templates\CampaignTemplate;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Exceptions\CampaignRejected;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Marketing\Services\Segments;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Campanhas (campanhas.md) e descadastro (consentimento.md): marketing
 * separado do transacional, so para consentimento CONCEDIDO ("desconhecido"
 * nao entra), conferido de novo a cada lote, ritmo limitado, cancelamento,
 * descadastro pelo link (inclusive um clique) e permissoes.
 */
class CampaignsTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
        $this->gerente = User::factory()->manager()->create(['email' => 'gerente.ficticio@exemplo.test']);
    }

    private function campaigns(): Campaigns
    {
        return app(Campaigns::class);
    }

    private function draft(string $segment = 'todos', array $params = []): Campaign
    {
        return $this->campaigns()->saveDraft(null, 'Promo de outubro', 'Novidade, {primeiro_nome}!', "Oi, {primeiro_nome}!\n\nCorte com 10% na terça.", $segment, $params, $this->gerente);
    }

    public function test_publico_so_tem_quem_aceitou_e_pode_receber(): void
    {
        $ok = $this->marketingCustomer();
        $this->cliente; // desconhecido
        $revogado = $this->marketingCustomer();
        app(MarketingConsentService::class)->revoke($revogado, $revogado->email, 'teste');
        $suprimido = $this->marketingCustomer();
        EmailSuppression::query()->create(['email' => $suprimido->email, 'reason' => 'bounce', 'suppressed_at' => now()]);
        $inativo = $this->marketingCustomer();
        $inativo->forceFill(['status' => CustomerStatus::Inactive])->save();

        $ids = app(Segments::class)->query('todos')->pluck('id')->all();

        $this->assertSame([$ok->id], $ids);
        $this->assertSame(MarketingConsent::Unknown, $this->cliente->refresh()->marketing_email_consent, 'desconhecido nunca vira aceito');
    }

    public function test_disparo_fotografa_o_publico_envia_aos_poucos_e_conclui(): void
    {
        CommunicationSettings::save(['campaign_per_minute' => 2], null);
        $clientes = collect(range(1, 3))->map(fn () => $this->marketingCustomer());
        $c = $this->draft();
        $chave = (string) Str::uuid();

        $c = $this->campaigns()->start($c, $this->gerente, $chave);
        $this->assertSame($c->id, $this->campaigns()->start($c, $this->gerente, $chave)->id, 'mesma chave = mesmo disparo');
        $this->assertSame(['sending', 3], [$c->status, $c->total_recipients]);
        $this->assertSame(0, EmailMessage::query()->where('category', 'marketing')->count(), 'nada sai na requisição do disparo');

        $this->assertSame(2, $this->campaigns()->processBatch()['entregues'], 'limite por minuto');
        $this->assertSame(1, $this->campaigns()->processBatch()['entregues']);
        $this->artisan('app:communication', ['task' => 'campaigns'])->assertSuccessful();

        $c->refresh();
        $this->assertSame(['completed', 3, 0], [$c->status, $c->sent_count, $c->failed_count]);
        $this->assertSame(3, EmailMessage::query()->where('category', MessageCategory::Marketing->value)->where('status', 'sent')->count());
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $m) => $m->hasTo($clientes[0]->email)
            && $m->email->oneClickUrl !== null && str_contains($m->render(), 'Não quero mais receber')
            && $m->email->subject === 'Novidade, '.strtok((string) $clientes[0]->name, ' ').'!');
        $this->assertSame(1, AuditLog::query()->where('action', 'campaign.started')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());

        $this->expectException(CampaignRejected::class);
        $this->campaigns()->start($c, $this->gerente, (string) Str::uuid());
    }

    public function test_quem_se_descadastra_depois_do_disparo_e_pulado(): void
    {
        $fica = $this->marketingCustomer();
        $sai = $this->marketingCustomer();
        $c = $this->campaigns()->start($this->draft(), $this->gerente, (string) Str::uuid());

        $this->post(URL::signedRoute('unsubscribe.one-click', ['customer' => $sai->public_id]), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->campaigns()->processBatch();

        $this->assertSame('skipped', CampaignRecipient::query()->where('customer_id', $sai->id)->value('status'));
        $this->assertSame([$fica->email], EmailMessage::query()->where('campaign_id', $c->id)->where('status', 'sent')->pluck('to_email')->all());
        $this->assertSame(1, $c->refresh()->skipped_count);
    }

    public function test_cancelar_para_o_que_nao_saiu(): void
    {
        CommunicationSettings::save(['campaign_per_minute' => 1], null);
        $this->marketingCustomer();
        $this->marketingCustomer();
        $c = $this->campaigns()->start($this->draft(), $this->gerente, (string) Str::uuid());
        $this->campaigns()->processBatch();

        $this->campaigns()->cancel($c, $this->gerente);
        $this->campaigns()->processBatch();

        $this->assertSame(['cancelled', 1, 1], [$c->refresh()->status, $c->sent_count, $c->skipped_count]);
        $this->assertSame(1, EmailMessage::query()->where('campaign_id', $c->id)->count());
    }

    public function test_campanha_nao_trava_o_transacional(): void
    {
        foreach (range(1, 5) as $i) {
            $this->marketingCustomer();
        }
        CommunicationSettings::save(['campaign_per_minute' => 1], null);
        $this->campaigns()->start($this->draft(), $this->gerente, (string) Str::uuid());

        $a = $this->bookOnline($this->terca, '10:00');

        $this->assertSame(MessageStatus::Sent, $this->emails('booking_confirmed')->sole()->status, 'confirmação sai na hora, sem esperar a campanha');
        $this->assertSame('sending', Campaign::query()->sole()->status);
        $this->assertNotNull($a->id);
    }

    public function test_link_de_descadastro_mostra_e_so_o_botao_descadastra(): void
    {
        $c = $this->marketingCustomer();
        $url = CampaignTemplate::unsubscribeUrl($c);

        $this->get($url)->assertOk()->assertSee('Não quero mais receber')->assertDontSee((string) $c->email);
        $this->assertSame(MarketingConsent::Granted, $c->refresh()->marketing_email_consent, 'abrir não descadastra');

        $this->post($url)->assertRedirect($url);
        $this->post($url);
        $c->refresh();
        $this->assertSame(MarketingConsent::Revoked, $c->marketing_email_consent);
        $this->assertTrue(EmailSuppression::isSuppressed($c->email));
        $this->assertSame(1, ConsentRecord::query()->where('customer_id', $c->id)->where('purpose', 'marketing_email')->where('source', 'unsubscribe_link')->count(), 'repetir não registra de novo');
        $this->get($url)->assertSee('Você está descadastrado');

        // Transacional continua.
        $this->bookOnline($this->terca, '10:00', $c);
        $this->assertSame(MessageStatus::Sent, $this->emails('booking_confirmed')->sole()->status);
    }

    public function test_link_de_descadastro_adulterado_e_recusado(): void
    {
        $vitima = $this->marketingCustomer();
        $atacante = $this->marketingCustomer();
        $url = CampaignTemplate::unsubscribeUrl($atacante);

        $this->post(str_replace($atacante->public_id, $vitima->public_id, $url))->assertForbidden();
        $this->post(route('unsubscribe.store', ['customer' => $vitima->public_id]))->assertForbidden();
        $this->post(route('unsubscribe.one-click', ['customer' => $vitima->public_id]))->assertForbidden();
        $this->assertSame(MarketingConsent::Granted, $vitima->refresh()->marketing_email_consent);
    }

    public function test_assunto_em_uma_linha_e_texto_nunca_vira_html(): void
    {
        $this->marketingCustomer(['name' => 'Ana <b>Teste</b>']);
        $c = $this->campaigns()->saveDraft(null, "Nome\nquebrado", "Oi\r\nBcc: intruso@exemplo.test", "<script>alert(1)</script>\n\nOi, {primeiro_nome}", 'todos', [], $this->gerente);
        $this->assertSame(['Nome quebrado', 'Oi Bcc: intruso@exemplo.test'], [$c->name, $c->subject]);

        $this->campaigns()->processBatch(); // ainda rascunho: nada sai
        $this->campaigns()->start($c, $this->gerente, (string) Str::uuid());
        $this->campaigns()->processBatch();

        Mail::assertSent(CommunicationMail::class, function (CommunicationMail $m) {
            $html = $m->render();

            return ! str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, 'Oi, Ana')
                && ! str_contains($m->email->subject, "\n") && $m->hasTo((string) Customer::query()->where('name', 'Ana <b>Teste</b>')->value('email'));
        });
    }

    public function test_teste_vai_so_para_a_equipe_e_nao_para_clientes(): void
    {
        $this->marketingCustomer();
        $c = $this->draft();

        $this->actingAs($this->gerente)->post(route('panel.campaigns.test', $c))->assertSessionHas('status');

        $m = EmailMessage::query()->sole();
        $this->assertSame(['campaign_test', 'gerente.ficticio@exemplo.test', MessageStatus::Sent], [$m->template, $m->to_email, $m->status]);
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail) => str_starts_with($mail->email->subject, '[TESTE]') && $mail->hasTo('gerente.ficticio@exemplo.test'));
        $this->assertSame('draft', $c->refresh()->status);
    }

    public function test_fluxo_pelo_painel_e_permissoes(): void
    {
        $this->marketingCustomer();
        $this->actingAs($this->gerente)->post(route('panel.campaigns.store'), [
            'name' => 'Volta', 'subject' => 'Sentimos sua falta', 'body' => 'Faz tempo, {primeiro_nome}.', 'segment' => 'sem_retorno', 'dias' => 45,
        ])->assertRedirect();
        $c = Campaign::query()->sole();
        $this->assertSame(['dias' => 45], $c->segment_params);

        $this->actingAs($this->gerente)->get(route('panel.campaigns.show', $c))->assertOk()->assertSee('Recebem hoje')->assertSee('Faz tempo, Maria.');
        $this->actingAs($this->gerente)->post(route('panel.campaigns.start', $c), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('confirm');
        $this->actingAs($this->gerente)->post(route('panel.campaigns.start', $c), ['request_key' => (string) Str::uuid(), 'confirm' => 1])
            ->assertSessionHasErrors('campaign'); // ninguem sem retorno ha 45 dias
        $this->actingAs($this->gerente)->put(route('panel.campaigns.update', $c), ['name' => 'Volta', 'subject' => 'Oi', 'body' => 'Oi', 'segment' => 'todos'])->assertRedirect();
        $this->actingAs($this->gerente)->post(route('panel.campaigns.start', $c), ['request_key' => (string) Str::uuid(), 'confirm' => 1])->assertSessionHas('status');
        $this->assertSame('sending', $c->refresh()->status);
        $this->actingAs($this->gerente)->get(route('panel.campaigns.edit', $c))->assertRedirect(route('panel.campaigns.show', $c));

        foreach ([$this->recepcao, User::factory()->role(StaffRole::Finance)->create(), User::factory()->role(StaffRole::Professional)->create()] as $u) {
            $this->actingAs($u)->get(route('panel.campaigns.index'))->assertForbidden();
            $this->actingAs($u)->post(route('panel.campaigns.cancel', $c))->assertForbidden();
        }
        $this->actingAs($this->gerente)->post(route('panel.campaigns.cancel', $c))->assertSessionHas('status');
    }
}

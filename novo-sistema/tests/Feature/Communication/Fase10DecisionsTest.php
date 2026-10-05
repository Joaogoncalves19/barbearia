<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\CommunicationRetention;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Marketing\Services\Campaigns;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Decisoes da aprovacao da Fase 10: D-05 (Resend atras de uma abstracao de
 * provedor), P10-01 (no maximo 4 campanhas por cliente em 30 dias) e P10-03
 * (retencao de 12 meses dos registros de comunicacao).
 */
class Fase10DecisionsTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    private string $chave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
        $this->chave = 're_teste_'.Str::random(24); // ficticia, gerada a cada teste
        Http::preventStrayRequests();
    }

    private function usarResend(): void
    {
        config(['barbearia.email_provider' => 'resend', 'services.resend.key' => $this->chave, 'services.resend.api_base' => 'https://resend.teste']);
    }

    public function test_resend_entrega_com_idempotencia_e_guarda_o_id_do_provedor(): void
    {
        $this->usarResend();
        Http::fake(['resend.teste/emails' => Http::response(['id' => 'em_ficticio_1'])]);

        $a = $this->bookOnline($this->terca, '10:00');

        $m = $this->emails('booking_confirmed')->sole();
        $this->assertSame([MessageStatus::Sent, 'resend', 'em_ficticio_1'], [$m->status, $m->provider, $m->provider_message_id]);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.$this->chave)
            && $r->hasHeader('Idempotency-Key', $m->public_id)
            && $r['to'] === ['"Cliente Fictício" <'.$this->cliente->email.'>']
            && str_contains((string) $r['html'], (string) $a->code)
            && $r['headers']['X-Barbearia-Message'] === $m->public_id
            && ! isset($r['headers']['List-Unsubscribe']));
    }

    public function test_resend_com_erro_volta_para_a_fila_sem_vazar_a_chave(): void
    {
        $this->usarResend();
        Queue::fake();
        $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();
        Http::fake(['resend.teste/emails' => Http::response(['message' => 'API key '.$this->chave.' is invalid'], 401)]);

        try {
            (new SendEmailMessage($m->id))->handle($this->outbox());
            $this->fail('deveria pedir nova tentativa');
        } catch (EmailDeliveryFailed $e) {
            $this->assertStringNotContainsString($this->chave, $e->getMessage());
        }

        $m->refresh();
        $this->assertSame([MessageStatus::Queued, 'resend'], [$m->status, $m->provider]);
        $this->assertStringContainsString('Resend HTTP 401', (string) $m->last_error);
        $this->assertStringNotContainsString($this->chave, (string) $m->last_error);
    }

    public function test_resend_sem_chave_nao_envia_nada(): void
    {
        config(['barbearia.email_provider' => 'resend', 'services.resend.key' => '']);
        Queue::fake();
        $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();

        $this->expectException(EmailDeliveryFailed::class);
        $this->expectExceptionMessage('RESEND_API_KEY vazia');
        try {
            (new SendEmailMessage($m->id))->handle($this->outbox());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_marketing_pelo_resend_leva_o_descadastro_de_um_clique(): void
    {
        $this->usarResend();
        Http::fake(['resend.teste/emails' => Http::response(['id' => 'em_mkt'])]);
        $c = $this->marketingCustomer();
        $gerente = User::factory()->manager()->create();
        $campanhas = app(Campaigns::class);
        $campanhas->start($campanhas->saveDraft(null, 'Teste', 'Oi', 'Texto', 'todos', [], $gerente), $gerente, (string) Str::uuid());
        $campanhas->processBatch();

        Http::assertSent(fn (HttpRequest $r) => str_contains((string) $r['headers']['List-Unsubscribe'], '/descadastro/'.$c->public_id.'/um-clique')
            && $r['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');
    }

    public function test_limite_de_4_campanhas_em_30_dias_so_conta_marketing(): void
    {
        $cliente = $this->marketingCustomer();
        $gerente = User::factory()->manager()->create();
        $campanhas = app(Campaigns::class);
        $disparar = function () use ($campanhas, $gerente): Campaign {
            $c = $campanhas->start($campanhas->saveDraft(null, 'C', 'Oi', 'Texto', 'todos', [], $gerente), $gerente, (string) Str::uuid());
            $campanhas->processBatch();

            return $c;
        };

        // E-mails transacionais do mesmo cliente (agendamento e lembrete) nao contam.
        $this->bookOnline($this->terca, '10:00', $cliente);
        foreach (range(1, 4) as $i) {
            $disparar();
            $this->travel(5)->days();
        }
        $quinta = $disparar();

        $this->assertSame(4, EmailMessage::query()->where('customer_id', $cliente->id)->where('template', 'campaign')->where('status', 'sent')->count());
        $this->assertSame('Limite de 4 campanhas em 30 dias.', CampaignRecipient::query()->where('campaign_id', $quinta->id)->value('skip_reason'));
        $this->assertSame(MessageStatus::Sent, $this->emails('booking_confirmed')->sole()->status);

        // Passou a janela da primeira: volta a receber.
        $this->travel(11)->days();
        $disparar();
        $this->assertSame(5, EmailMessage::query()->where('customer_id', $cliente->id)->where('template', 'campaign')->where('status', 'sent')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_limite_conferido_tambem_na_hora_do_envio(): void
    {
        $cliente = $this->marketingCustomer();
        $gerente = User::factory()->manager()->create();
        $campanhas = app(Campaigns::class);
        Queue::fake(); // os cinco ficam na fila ao mesmo tempo (o lote nao barra)
        foreach (range(1, 5) as $i) {
            $campanhas->start($campanhas->saveDraft(null, 'C'.$i, 'Oi', 'Texto', 'todos', [], $gerente), $gerente, (string) Str::uuid());
        }
        $campanhas->processBatch();
        $fila = EmailMessage::query()->where('customer_id', $cliente->id)->where('template', 'campaign')->orderBy('id')->get();
        $this->assertCount(5, $fila);

        foreach ($fila as $m) {
            $this->outbox()->deliver($m->id);
        }

        $this->assertSame([4, 1], [
            EmailMessage::query()->where('template', 'campaign')->where('status', 'sent')->count(),
            EmailMessage::query()->where('template', 'campaign')->where('status', 'suppressed')->where('skip_reason', 'Limite de 4 campanhas em 30 dias.')->count(),
        ]);
    }

    public function test_retencao_de_12_meses_anonimiza_emails_e_destinatarios_e_apaga_avisos(): void
    {
        $velho = $this->marketingCustomer();
        app(MarketingConsentService::class)->grant($velho, 'teste'); // prova de consentimento: nunca entra na retencao
        $gerente = User::factory()->manager()->create();
        $campanhas = app(Campaigns::class);
        $campanhas->start($campanhas->saveDraft(null, 'Antiga', 'Novidade, {primeiro_nome}', 'Texto', 'todos', [], $gerente), $gerente, (string) Str::uuid());
        $campanhas->processBatch();
        CustomerNotification::query()->create(['customer_id' => $velho->id, 'kind' => 'reminder', 'message' => 'Aviso antigo']);

        $this->travel(13)->months();
        $this->bookOnline(CarbonImmutable::parse(BusinessTime::today())->addDay()->toDateString(), '19:00'); // recente: fica
        $this->assertNotSame([], app(IntegrityChecker::class)->violations(), 'R52 acusa o que passou do prazo');

        $n = app(CommunicationRetention::class)->run();
        $this->assertSame(['emails' => 1, 'destinatarios' => 1, 'avisos' => 1], $n);
        $this->assertSame(['emails' => 0, 'destinatarios' => 0, 'avisos' => 0], app(CommunicationRetention::class)->run(), 'idempotente');

        $m = EmailMessage::query()->where('template', 'campaign')->sole();
        $this->assertSame(['[removido]', null, null, MessageStatus::Sent, 'campaign', $velho->id], [$m->to_email, $m->to_name, $m->subject, $m->status, $m->template, $m->customer_id]);
        $this->assertNotNull($m->purged_at);
        $this->assertSame('[removido]', CampaignRecipient::query()->sole()->email);
        $this->assertSame(0, CustomerNotification::query()->where('message', 'Aviso antigo')->count());
        $this->assertNotSame('[removido]', $this->emails('booking_confirmed')->sole()->to_email, 'registro recente fica');
        $log = AuditLog::query()->where('action', 'retention.communication')->sole();
        $this->assertStringNotContainsString((string) $velho->email, (string) json_encode($log->toArray()));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
        $this->assertSame(1, DB::table('consent_records')->where('customer_id', $velho->id)->count(), 'prova de consentimento não é tocada');
    }
}

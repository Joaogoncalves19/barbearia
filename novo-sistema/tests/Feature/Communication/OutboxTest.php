<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Templates\TemplateRegistry;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Customers\Services\MarketingConsentService;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Fila central de e-mails (emails.md, fila.md): envio assincrono depois do
 * commit, unicidade, novas tentativas com espera crescente, falha
 * definitiva, reenvio, consentimento conferido na hora e nada sensivel no
 * registro.
 */
class OutboxTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
    }

    public function test_agendamento_gera_um_email_de_confirmacao_enviado_pela_fila(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');

        $m = $this->emails('booking_confirmed')->sole();
        $this->assertSame([MessageStatus::Sent, MessageCategory::Transactional, 1, 'booking_confirmed:'.$a->id], [$m->status, $m->category, $m->attempts, $m->dedupe_key]);
        $this->assertSame(['appointment_id' => $a->id], $m->params, 'só identificadores no registro; o corpo é montado na hora');
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail) => $mail->hasTo($this->cliente->email)
            && str_contains($mail->render(), $a->code) && $mail->email->unsubscribeUrl === null);
        $this->assertNotNull($m->sent_at);
    }

    public function test_encaixe_e_agendamento_da_equipe_sem_email_nao_quebram(): void
    {
        $this->walkIn();
        $this->assertSame(0, EmailMessage::query()->count(), 'encaixe não gera confirmação');
    }

    public function test_o_envio_espera_o_commit_e_nao_sai_se_a_transacao_desfaz(): void
    {
        try {
            DB::transaction(function () {
                $this->bookOnline($this->terca, '10:00');
                Mail::assertNothingSent(); // nada sai antes do commit
                throw new \RuntimeException('desfaz');
            });
        } catch (\RuntimeException) {
        }

        Mail::assertNothingSent();
        $this->assertSame(0, EmailMessage::query()->count());
    }

    public function test_job_vai_para_a_fila_de_emails_com_tentativas_e_espera_crescente(): void
    {
        Queue::fake();
        $this->bookOnline($this->terca, '10:00');

        Queue::assertPushedOn('emails', SendEmailMessage::class);
        $job = new SendEmailMessage(1);
        $this->assertSame([4, [60, 300, 900, 3600], 60], [$job->tries, $job->backoff(), $job->timeout]);
        $this->assertSame(10, config('mail.mailers.smtp.timeout'), 'a conexão com o provedor nunca espera indefinidamente');
    }

    public function test_mesma_chave_nunca_enfileira_duas_vezes_e_job_repetido_nao_reenvia(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();

        $this->outbox()->queue('booking_confirmed', (string) $this->cliente->email, null, $this->cliente, ['appointment_id' => $a->id], 'booking_confirmed:'.$a->id);
        (new SendEmailMessage($m->id))->handle($this->outbox()); // o mesmo job de novo (retentativa, worker duplicado)
        (new SendEmailMessage($m->id))->handle($this->outbox());

        $this->assertSame(1, EmailMessage::query()->where('template', 'booking_confirmed')->count());
        Mail::assertSent(CommunicationMail::class, 1);
    }

    public function test_falha_volta_para_a_fila_esgotada_vira_falha_e_o_reenvio_entrega_uma_vez(): void
    {
        Queue::fake();
        $a = $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();
        $this->failingMailer();

        // 1a tentativa: erro do provedor, volta para a fila (o worker tenta de novo com espera).
        try {
            (new SendEmailMessage($m->id))->handle($this->outbox());
            $this->fail('deveria lançar o erro para o worker tentar de novo');
        } catch (EmailDeliveryFailed $e) {
            $this->assertNull($e->getPrevious(), 'a exceção original (com a senha) não vai para o worker');
            $this->assertStringNotContainsString('segredo-ficticio-123', $e->getMessage());
        }
        $m->refresh();
        $this->assertSame([MessageStatus::Queued, 1], [$m->status, $m->attempts]);
        $this->assertStringContainsString('password=***', (string) $m->last_error);
        $this->assertStringNotContainsString('segredo-ficticio-123', (string) $m->last_error, 'credencial nunca vai para o registro');

        // Tentativas esgotadas: falha definitiva, com o erro.
        (new SendEmailMessage($m->id))->failed($e);
        $m->refresh();
        $this->assertSame(MessageStatus::Failed, $m->status);
        $this->assertNotNull($m->failed_at);

        // Reenvio manual (comando/painel): volta para a fila e entrega uma vez.
        $transporte = $this->workingMailer();
        $this->assertTrue($this->outbox()->retry($m));
        $this->assertFalse($this->outbox()->retry($m->refresh()), 'só o que falhou pode ser reenviado');
        Queue::assertPushed(SendEmailMessage::class, 2);
        (new SendEmailMessage($m->id))->handle($this->outbox());
        (new SendEmailMessage($m->id))->handle($this->outbox());

        $this->assertSame([MessageStatus::Sent, 2], [$m->refresh()->status, $m->attempts], '2 tentativas: a que falhou e a que entregou; o job repetido nao conta');
        $this->assertCount(1, $transporte->messages());
        $this->assertStringContainsString($a->code, $transporte->messages()->first()->toString());
    }

    public function test_comando_de_reenvio_recoloca_as_falhas_na_fila(): void
    {
        Queue::fake();
        $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();
        $this->outbox()->markFailed($m->id, null);

        $this->artisan('app:communication', ['task' => 'retry'])->expectsOutputToContain('1')->assertSuccessful();

        $this->assertSame(MessageStatus::Queued, $m->refresh()->status);
    }

    public function test_marketing_so_com_consentimento_concedido_e_sem_supressao_conferidos_na_hora(): void
    {
        Queue::fake();
        $c = Campaign::query()->create(['name' => 'Teste', 'channel' => 'email', 'template' => 'campaign', 'subject' => 'Oi', 'body' => 'Texto', 'segment' => 'todos', 'status' => 'sending']);
        $cliente = $this->marketingCustomer();
        $desconhecido = $this->cliente; // consentimento "desconhecido"
        $this->assertSame(MarketingConsent::Unknown, $desconhecido->marketing_email_consent);

        $ok = $this->outbox()->queue('campaign', (string) $cliente->email, $cliente->name, $cliente, ['campaign_id' => $c->id], 'c1', campaignId: $c->id);
        $sem = $this->outbox()->queue('campaign', (string) $desconhecido->email, null, $desconhecido, ['campaign_id' => $c->id], 'c2', campaignId: $c->id);
        // Descadastrou DEPOIS de enfileirado: vale o estado da hora do envio.
        $depois = $this->marketingCustomer();
        $m3 = $this->outbox()->queue('campaign', (string) $depois->email, null, $depois, ['campaign_id' => $c->id], 'c3', campaignId: $c->id);
        app(MarketingConsentService::class)->revoke($depois, $depois->email, 'teste');

        foreach ([$ok, $sem, $m3] as $m) {
            $this->outbox()->deliver($m->id);
        }

        $this->assertSame(MessageStatus::Sent, $ok->refresh()->status);
        $this->assertSame([MessageStatus::Suppressed, 'Sem consentimento de marketing.'], [$sem->refresh()->status, $sem->skip_reason]);
        $this->assertSame(MessageStatus::Suppressed, $m3->refresh()->status);
        Mail::assertSent(CommunicationMail::class, 1);
    }

    public function test_descadastro_de_marketing_nao_bloqueia_transacional_mas_devolucao_bloqueia(): void
    {
        app(MarketingConsentService::class)->revoke($this->cliente, $this->cliente->email, 'teste');
        $this->bookOnline($this->terca, '10:00');
        $this->assertSame(MessageStatus::Sent, $this->emails('booking_confirmed')->sole()->status, 'confirmação é necessária ao serviço');

        EmailSuppression::query()->where('email', $this->cliente->email)->update(['reason' => 'bounce']);
        $this->bookOnline($this->terca, '15:00');
        $this->assertSame(MessageStatus::Suppressed, $this->emails('booking_confirmed')->last()->status, 'endereço que devolve não recebe nada');
    }

    public function test_registro_e_historico_nao_muda_conteudo_nem_e_apagado(): void
    {
        $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();

        $this->expectException(DomainRuleViolation::class);
        $m->forceFill(['to_email' => 'outro@exemplo.test'])->save();
    }

    public function test_previa_de_todos_os_modelos_renderiza_com_dados_ficticios(): void
    {
        foreach (app(TemplateRegistry::class)->all() as $key => $t) {
            $html = (new CommunicationMail($t->preview(), 'previa'))->render();
            $this->assertStringContainsString('<html', $html, $key);
            $this->assertStringNotContainsString('{primeiro_nome}', $html, $key);
        }
    }
}

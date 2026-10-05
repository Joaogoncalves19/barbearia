<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\Outbox;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * E-mails de assinatura (D-50): ativacao, falha de pagamento e cancelamento,
 * SO depois da consolidacao do estado (nunca so porque chegou um webhook) e
 * conferidos de novo na hora do envio. Renovacao: so aviso na conta.
 */
class SubscriptionMessagesTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Mail::fake();
    }

    /** @return list<string> */
    private function templates(): array
    {
        return EmailMessage::query()->where('template', 'like', 'subscription_%')->orderBy('id')->pluck('template')->all();
    }

    public function test_ativacao_sai_uma_vez_mesmo_com_webhook_repetido(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s)))->assertOk(); // reenvio do Stripe

        $this->assertSame(['subscription_activated'], $this->templates());
        $this->assertSame(MessageStatus::Sent, EmailMessage::query()->where('template', 'subscription_activated')->sole()->status);
        $this->assertSame(1, CustomerNotification::query()->where('kind', 'subscription')->count());
    }

    public function test_link_pendente_que_vence_ou_e_cancelado_nao_gera_email(): void
    {
        $s = $this->startSignup($this->cliente);
        $this->postWebhook($this->event('customer.subscription.created', $this->subObject($s, 'incomplete')))->assertOk();
        $this->manager()->cancel($s->refresh(), true, 'desistiu', $this->recepcao);

        $this->assertSame([], $this->templates());
    }

    public function test_falha_de_pagamento_e_recuperacao_antes_do_envio(): void
    {
        $s = $this->activeSubscription($this->cliente);
        Queue::fake(); // e-mail parado na fila

        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'past_due')))->assertOk();
        $this->postWebhook($this->event('customer.subscription.updated', $this->subObject($s, 'past_due')))->assertOk(); // repetido
        $this->assertSame(SubscriptionStatus::PastDue, $s->refresh()->status);
        $falha = EmailMessage::query()->where('template', 'subscription_payment_failed')->sole();

        // O Stripe cobrou na nova tentativa antes de o e-mail sair: o aviso de falha nao sai.
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle')))->assertOk();
        $this->assertSame(SubscriptionStatus::Active, $s->refresh()->status);
        (new SendEmailMessage($falha->id))->handle(app(Outbox::class));

        $this->assertSame(MessageStatus::Skipped, $falha->refresh()->status);
        Mail::assertSent(CommunicationMail::class, 1); // so a ativacao
    }

    public function test_cancelamento_agendado_e_desfeito_antes_do_envio_nao_sai(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->fakeStripe(['stripe.teste/v1/subscriptions/*' => fn ($req) => Http::response($this->subObject($s, 'active', ['cancel_at_period_end' => $req['cancel_at_period_end'] === 'true' || $req['cancel_at_period_end'] === true]))]);
        Queue::fake();

        $this->manager()->cancel($s, false, 'mudou de cidade', $this->cliente);
        $this->assertSame(SubscriptionStatus::CancelScheduled, $s->refresh()->status);
        $aviso = EmailMessage::query()->where('template', 'subscription_cancel_scheduled')->sole();
        $this->manager()->reactivate($s, $this->cliente);
        (new SendEmailMessage($aviso->id))->handle(app(Outbox::class));

        $this->assertSame(MessageStatus::Skipped, $aviso->refresh()->status);
    }

    public function test_cancelamento_efetivo_avisa_uma_vez(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-10-20 08:00')])))->assertOk();
        $this->postWebhook($this->event('customer.subscription.deleted', $this->subObject($s, 'canceled', ['ended_at' => $this->ts('2026-10-20 08:00')])))->assertOk();

        $this->assertSame(['subscription_activated', 'subscription_cancelled'], $this->templates());
        $this->assertSame(MessageStatus::Sent, EmailMessage::query()->where('template', 'subscription_cancelled')->sole()->status);
    }

    public function test_link_de_pagamento_por_email_idempotente_sem_novo_link_e_no_historico(): void
    {
        $s = $this->startSignup($this->cliente);
        $url = $s->checkout_url;

        $this->actingAs($this->recepcao)->post(route('panel.subscriptions.link.email', $s))->assertSessionHas('status');
        $this->actingAs($this->recepcao)->post(route('panel.subscriptions.link.email', $s))->assertSessionHas('status', 'Este link já tinha sido enviado por e-mail.');

        $m = EmailMessage::query()->where('template', 'subscription_payment_link')->sole();
        $this->assertSame([MessageStatus::Sent, 'transactional'], [$m->status, $m->category->value]);
        $this->assertStringNotContainsString((string) $url, (string) json_encode($m->params), 'o link não fica no registro');
        Mail::assertSent(\App\Modules\Communication\Mail\CommunicationMail::class, fn ($mail) => str_contains($mail->render(), (string) $url));
        \Illuminate\Support\Facades\Http::assertSentCount(1); // uma sessão no Stripe: o envio nunca gera outro link
        $this->assertSame($url, $s->refresh()->checkout_url);
        $this->assertSame(1, $s->events()->where('kind', 'link_emailed')->count());
        $this->assertSame(1, \App\Modules\System\Models\AuditLog::query()->where('action', 'subscription.link_emailed')->count());

        $financeiro = \App\Modules\Identity\Models\User::factory()->role(\App\Modules\Identity\Enums\StaffRole::Finance)->create();
        $this->actingAs($financeiro)->post(route('panel.subscriptions.link.email', $s))->assertForbidden();
    }

    public function test_link_substituido_ou_vencido_nao_sai(): void
    {
        $s = $this->startSignup($this->cliente);
        Queue::fake();
        $this->checkout()->emailLink($s, $this->recepcao);
        $antigo = EmailMessage::query()->where('template', 'subscription_payment_link')->sole();

        $this->travel(5)->minutes(); // passado o duplo clique (mesmo link por 2 min)
        $novo = $this->startSignup($this->cliente); // novo link expira o anterior
        $this->assertNotSame($s->id, $novo->id);
        (new SendEmailMessage($antigo->id))->handle(app(Outbox::class));
        $this->assertSame(MessageStatus::Skipped, $antigo->refresh()->status);

        $this->travel(2)->days();
        $this->expectException(\App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation::class);
        $this->checkout()->emailLink($novo->refresh(), $this->recepcao);
    }

    public function test_renovacao_so_aviso_na_conta(): void
    {
        $s = $this->activeSubscription($this->cliente);
        $this->postWebhook($this->event('invoice.paid', $this->invoice($s, '2026-11-05 08:00', '2026-12-05 08:00', 'subscription_cycle')))->assertOk();

        $this->assertSame(['subscription_activated'], $this->templates(), 'renovação não manda e-mail (D-50)');
        $this->assertStringContainsString('renovada', (string) CustomerNotification::query()->where('kind', 'subscription')->latest('id')->value('message'));
    }
}

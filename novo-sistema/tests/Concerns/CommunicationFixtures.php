<?php

namespace Tests\Concerns;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\Outbox;
use App\Modules\Communication\Services\Reminders;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Comunicacao ficticia (Fase 10) sobre a agenda/atendimento dos testes:
 * relogio na segunda 05/10/2026 08:00 (Sao Paulo), caixa aberto, e-mails
 * entregues ao Mail::fake (fila "sync" nos testes: o job roda depois do
 * commit, na mesma requisicao).
 */
trait CommunicationFixtures
{
    use CheckoutFixtures;

    protected function setUpCommunication(): void
    {
        $this->setUpCheckout();
        $this->openCash();
        Mail::fake();
    }

    protected function outbox(): Outbox
    {
        return app(Outbox::class);
    }

    protected function reminders(): Reminders
    {
        return app(Reminders::class);
    }

    /** Agendamento feito pelo cliente no site (gera confirmacao por e-mail). */
    protected function bookOnline(string $date, string $time, ?Customer $customer = null): Appointment
    {
        return $this->booking()->book(new BookingRequest(
            service: $this->corte,
            professional: $this->joao,
            start: $this->at($date, $time),
            channel: Channel::Customer,
            source: AppointmentSource::Online,
            customer: $customer ?? $this->cliente,
            actor: $customer ?? $this->cliente,
        ));
    }

    /** Atendimento concluido hoje (segunda) para o cliente. */
    protected function completedFor(Customer $customer, string $time = '10:00'): Attendance
    {
        $ag = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $customer,
        ));
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
    }

    protected function marketingCustomer(array $extra = []): Customer
    {
        $c = Customer::factory()->create($extra);
        $c->forceFill(['marketing_email_consent' => MarketingConsent::Granted, 'marketing_consent_updated_at' => now()])->save();

        return $c;
    }

    /** E-mails do modelo, na ordem. */
    protected function emails(string $template): Collection
    {
        return EmailMessage::query()->where('template', $template)->orderBy('id')->get();
    }

    /** Provedor que recusa a entrega (falha de SMTP simulada, com "senha" na mensagem). */
    protected function failingMailer(): void
    {
        Mail::extend('falha-teste', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection could not be established with host smtp.exemplo.test: password=segredo-ficticio-123');
            }

            public function __toString(): string
            {
                return 'falha-teste';
            }
        });
        config(['mail.mailers.falha-teste' => ['transport' => 'falha-teste'], 'mail.default' => 'falha-teste']);
        $this->realMailManager()->forgetMailers();
        Mail::swap($this->realMailManager()); // sai do Mail::fake: entrega de verdade (e falha)
    }

    /** Provedor que aceita (memoria), depois de uma falha simulada. */
    protected function workingMailer(): ArrayTransport
    {
        config(['mail.mailers.array-teste' => ['transport' => 'array'], 'mail.default' => 'array-teste']);
        $this->realMailManager()->forgetMailers();
        Mail::swap($this->realMailManager());

        /** @var ArrayTransport */
        return $this->realMailManager()->mailer('array-teste')->getSymfonyTransport();
    }

    private function realMailManager(): MailManager
    {
        $root = Mail::getFacadeRoot();

        return $root instanceof MailFake ? $root->manager : $root;
    }
}

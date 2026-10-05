<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Scheduling\Models\AppointmentReminder;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Lembretes (D-51, lembretes.md): vespera a partir das 9h e 2 h antes, um
 * por HORARIO (agendador rodando duas vezes nao duplica), conferidos na hora
 * do envio (cancelado/remarcado nao recebe o lembrete antigo), preferencia do
 * cliente e link de confirmacao de presenca assinado.
 */
class RemindersTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
    }

    public function test_vespera_a_partir_das_9h_com_email_e_aviso_e_rodar_de_novo_nao_duplica(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');

        $this->clockAt('08:30');
        $this->assertSame(0, $this->reminders()->run()['day_before'], 'antes das 9h não sai lembrete da véspera');

        $this->clockAt('09:00');
        $this->assertSame(1, $this->reminders()->run()['day_before']);
        $segunda = $this->reminders()->run();
        $this->artisan('app:communication', ['task' => 'reminders'])->assertSuccessful();

        $this->assertSame([0, 1], [$segunda['day_before'], $segunda['ja_lembrados']]);
        $this->assertSame(1, AppointmentReminder::query()->where('appointment_id', $a->id)->count());
        $m = $this->emails('reminder_day_before')->sole();
        $this->assertSame(MessageStatus::Sent, $m->status);
        $this->assertSame(1, CustomerNotification::query()->where('customer_id', $this->cliente->id)->where('kind', 'reminder')->count());
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail) => $mail->email->subject === 'Lembrete: seu horário é amanhã às 10:00'
            && str_contains($mail->render(), 'Confirmar presença'));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_horas_antes_so_na_janela_configurada(): void
    {
        $a = $this->bookOnline($this->segunda, '11:00');

        $this->clockAt('08:30');
        $this->assertSame(0, $this->reminders()->run()['hours_before'], '2 h e meia antes: ainda não');
        $this->clockAt('09:05');
        $this->assertSame(1, $this->reminders()->run()['hours_before']);

        $this->assertSame('hours_before', AppointmentReminder::query()->where('appointment_id', $a->id)->sole()->kind->value);
        $this->assertSame(MessageStatus::Sent, $this->emails('reminder_hours_before')->sole()->status);

        CommunicationSettings::save(['reminder_hours_before' => 4, 'reminder_day_before_enabled' => false], null);
        $this->bookOnline($this->segunda, '12:30');
        $this->assertSame(1, $this->reminders()->run()['hours_before'], 'configurável (4 h)');
    }

    public function test_cancelado_depois_de_enfileirado_nao_recebe_o_lembrete(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        Queue::fake(); // o lembrete fica na fila (provedor lento)
        $this->clockAt('09:00');
        $this->reminders()->run();
        $m = $this->emails('reminder_day_before')->sole();

        $this->booking()->cancel($a, Channel::Customer, $this->cliente);
        (new SendEmailMessage($m->id))->handle($this->outbox());

        $this->assertSame(MessageStatus::Skipped, $m->refresh()->status);
        $this->assertStringContainsString('cancelado', (string) $m->skip_reason);
        Mail::assertNotSent(CommunicationMail::class, fn (CommunicationMail $mail) => str_starts_with($mail->email->subject, 'Lembrete'));
        $this->assertSame(0, $this->reminders()->run()['day_before'], 'cancelado não entra na rotina');
    }

    public function test_remarcado_nunca_recebe_o_lembrete_do_horario_antigo_e_ganha_o_do_novo(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        Queue::fake();
        $this->clockAt('09:00');
        $this->reminders()->run();
        $antigo = $this->emails('reminder_day_before')->sole();

        $this->booking()->reschedule($a, $this->at($this->terca, '15:00'), null, Channel::Staff, $this->recepcao);
        (new SendEmailMessage($antigo->id))->handle($this->outbox());
        $this->assertSame(MessageStatus::Skipped, $antigo->refresh()->status);
        $this->assertStringContainsString('horário antigo', (string) $antigo->skip_reason);

        $this->assertSame(1, $this->reminders()->run()['day_before'], 'novo horário, novo lembrete');
        $novo = $this->emails('reminder_day_before')->last();
        $this->assertNotSame($antigo->id, $novo->id);
        (new SendEmailMessage($novo->id))->handle($this->outbox());
        $this->assertSame(MessageStatus::Sent, $novo->refresh()->status);
        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail) => $mail->email->subject === 'Lembrete: seu horário é amanhã às 15:00');
        $this->assertSame(2, AppointmentReminder::query()->where('appointment_id', $a->id)->count());
    }

    public function test_cliente_que_desligou_lembretes_por_email_nao_recebe_mas_ve_o_aviso(): void
    {
        $this->cliente->forceFill(['email_reminders_enabled' => false])->save();
        $this->bookOnline($this->terca, '10:00');
        $this->clockAt('09:00');
        $this->reminders()->run();

        $this->assertCount(0, $this->emails('reminder_day_before'));
        $this->assertSame(1, CustomerNotification::query()->where('kind', 'reminder')->count());
        $this->assertSame(MessageStatus::Sent, $this->emails('booking_confirmed')->sole()->status, 'confirmação continua');
    }

    public function test_aguardando_confirmacao_da_barbearia_nao_recebe_lembrete(): void
    {
        BookingPolicy::save(['requires_confirmation' => true]);
        $this->bookOnline($this->terca, '10:00');
        $this->clockAt('09:00');

        $this->assertSame(['day_before' => 0, 'hours_before' => 0, 'ja_lembrados' => 0], $this->reminders()->run());
    }

    public function test_confirmacao_de_presenca_pelo_link_assinado(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        $url = URL::temporarySignedRoute('presence.show', $a->starts_at, ['appointment' => $a->code]);

        $this->get($url)->assertOk()->assertSee('Confirmar que vou')->assertDontSee((string) $this->cliente->email);
        $this->assertNull($a->refresh()->presence_confirmed_at, 'abrir o link não confirma');

        $this->post($url)->assertRedirect($url);
        $this->post($url)->assertRedirect($url);
        $this->assertNotNull($a->refresh()->presence_confirmed_at);
        $this->assertSame(1, AppointmentEvent::query()->where('appointment_id', $a->id)->where('type', 'presence_confirmed')->count(), 'confirmar de novo não repete');
        $this->get($url)->assertSee('Presença confirmada');
    }

    public function test_link_de_presenca_adulterado_de_outro_agendamento_ou_vencido_e_recusado(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        $b = $this->bookOnline($this->terca, '11:00', Customer::factory()->create());
        $url = URL::temporarySignedRoute('presence.show', $a->starts_at, ['appointment' => $a->code]);

        $this->get(str_replace($a->code, $b->code, $url))->assertForbidden();
        $this->post(str_replace($a->code, $b->code, $url))->assertForbidden();
        $this->get(route('presence.show', ['appointment' => $a->code]))->assertForbidden();
        $this->assertNull($b->refresh()->presence_confirmed_at);

        $this->travelTo($a->starts_at->addMinute());
        $this->post($url)->assertForbidden();
        $this->assertNull($a->refresh()->presence_confirmed_at);
    }

    public function test_agendamento_cancelado_nao_confirma_presenca(): void
    {
        $a = $this->bookOnline($this->terca, '10:00');
        $url = URL::temporarySignedRoute('presence.show', $a->starts_at, ['appointment' => $a->code]);
        $this->booking()->cancel($a, Channel::Staff, $this->recepcao);

        $this->get($url)->assertOk()->assertSee('não está mais ativo');
        $this->post($url)->assertRedirect($url);
        $this->assertNull($a->refresh()->presence_confirmed_at);
    }
}

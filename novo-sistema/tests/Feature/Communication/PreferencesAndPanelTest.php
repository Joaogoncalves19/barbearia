<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Models\GatewayEvent;
use App\Modules\Subscriptions\Services\GatewayEventRetention;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Preferencias e consentimento do cliente (consentimento.md), avisos na
 * conta, registro de e-mails e configuracao no painel, e a retencao dos
 * eventos do Stripe (P9-10).
 */
class PreferencesAndPanelTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
    }

    public function test_salvar_sem_escolher_nao_inventa_consentimento(): void
    {
        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), ['reminders' => 1])
            ->assertRedirect(route('account.profile.edit'))->assertSessionHas('status', 'Nada mudou nas suas preferências.');

        $this->assertSame(MarketingConsent::Unknown, $this->cliente->refresh()->marketing_email_consent);
        $this->assertSame(0, ConsentRecord::query()->count());
    }

    public function test_aceitar_e_recusar_marketing_com_prova_e_supressao(): void
    {
        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), ['marketing' => 'sim', 'reminders' => 1]);
        $this->assertSame(MarketingConsent::Granted, $this->cliente->refresh()->marketing_email_consent);

        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), ['marketing' => 'nao', 'reminders' => 1]);
        $this->assertSame(MarketingConsent::Revoked, $this->cliente->refresh()->marketing_email_consent);
        $this->assertTrue(EmailSuppression::isSuppressed($this->cliente->email));

        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), ['marketing' => 'sim', 'reminders' => 1]);
        $this->assertFalse(EmailSuppression::isSuppressed($this->cliente->email), 'aceitar de novo tira só a supressão por descadastro');
        $this->assertSame(['granted', 'revoked', 'granted'], ConsentRecord::query()->where('purpose', 'marketing_email')->orderBy('id')->pluck('action')->map->value->all());
        $this->assertSame('account_preferences', ConsentRecord::query()->value('source'));

        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), ['marketing' => 'talvez'])->assertSessionHasErrors('marketing');
    }

    public function test_desligar_lembretes_registrado_e_nao_afeta_outros_clientes(): void
    {
        $outro = Customer::factory()->create();
        $this->actingAs($this->cliente, 'customer')->put(route('account.preferences.update'), [])->assertSessionHas('status');

        $this->assertFalse($this->cliente->refresh()->email_reminders_enabled);
        $this->assertTrue($outro->refresh()->email_reminders_enabled);
        $r = ConsentRecord::query()->sole();
        $this->assertSame(['reminder_email', 'revoked', $this->cliente->id], [$r->purpose, $r->action->value, $r->customer_id]);
        $this->actingAs($this->cliente, 'customer')->get(route('account.profile.edit'))->assertOk()->assertSee('Você ainda não escolheu');
    }

    public function test_avisos_so_do_proprio_cliente(): void
    {
        $outro = Customer::factory()->create();
        CustomerNotification::query()->create(['customer_id' => $this->cliente->id, 'kind' => 'reminder', 'message' => 'Aviso do cliente', 'link' => url('/minha-conta')]);
        CustomerNotification::query()->create(['customer_id' => $outro->id, 'kind' => 'reminder', 'message' => 'Aviso de outra pessoa']);

        $this->actingAs($this->cliente, 'customer')->get(route('account.notifications'))->assertOk()->assertSee('Aviso do cliente')->assertDontSee('Aviso de outra pessoa');
        $this->actingAs($this->cliente, 'customer')->get(route('account.home'))->assertSee('account-menu__count">1', false)->assertSee('1 novo');
        $this->actingAs($this->cliente, 'customer')->post(route('account.notifications.read'))->assertRedirect();

        $this->assertNotNull(CustomerNotification::query()->where('customer_id', $this->cliente->id)->value('read_at'));
        $this->assertNull(CustomerNotification::query()->where('customer_id', $outro->id)->value('read_at'));
    }

    public function test_registro_de_emails_mascara_endereco_e_reenvio_exige_permissao(): void
    {
        $this->bookOnline($this->terca, '10:00');
        $m = $this->emails('booking_confirmed')->sole();
        $this->outbox()->markFailed($m->id, null);
        EmailMessage::query()->whereKey($m->id)->update(['status' => 'failed']);
        $gerente = User::factory()->manager()->create();

        $this->actingAs($gerente)->get(route('panel.emails.index'))->assertOk()
            ->assertSee($m->refresh()->maskedEmail())->assertDontSee((string) $this->cliente->email)->assertSee('Reenviar');
        $this->actingAs($gerente)->get(route('panel.emails.preview', 'reminder_day_before'))->assertOk()->assertSee('Confirmar presença');
        $this->actingAs($gerente)->get(route('panel.emails.preview', 'nao-existe'))->assertNotFound();

        $this->actingAs($this->recepcao)->get(route('panel.emails.index'))->assertForbidden();
        $this->actingAs($this->recepcao)->post(route('panel.emails.retry', $m))->assertForbidden();
        $this->actingAs($gerente)->post(route('panel.emails.retry', $m))->assertSessionHas('status');

        $this->assertSame(MessageStatus::Sent, $m->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'email.retried')->count());
    }

    public function test_configuracao_so_do_proprietario_com_limites_e_auditoria(): void
    {
        $dono = User::factory()->owner()->create();
        $this->actingAs(User::factory()->manager()->create())->get(route('panel.communication.settings'))->assertForbidden();
        $this->actingAs($dono)->get(route('panel.communication.settings'))->assertOk();

        $base = CommunicationSettings::current()->toArray();
        $this->actingAs($dono)->put(route('panel.communication.settings.update'), [...$base, 'reminder_day_before_hour' => 30])->assertSessionHasErrors('reminder_day_before_hour');
        $this->actingAs($dono)->put(route('panel.communication.settings.update'), [...$base, 'reminder_day_before_enabled' => 1, 'reminder_hours_before_enabled' => 1, 'review_request_enabled' => 1, 'reminder_day_before_hour' => 10])
            ->assertRedirect(route('panel.communication.settings'));

        $this->assertSame(10, CommunicationSettings::current()->int('reminder_day_before_hour'));
        $this->assertSame(1, AuditLog::query()->where('action', 'communication.settings_changed')->count());
    }

    public function test_menu_mostra_so_o_que_a_pessoa_pode(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get(route('panel.home'))->assertSee('Campanhas')->assertSee('E-mails enviados')->assertSee('Lembretes e avisos')->assertSee('Avaliações');
        $this->actingAs($this->recepcao)->get(route('panel.home'))->assertSee('Avaliações')->assertDontSee('Campanhas')->assertDontSee('E-mails enviados');
        $this->actingAs(User::factory()->role(StaffRole::Finance)->create())->get(route('panel.home'))->assertDontSee('Avaliações');
    }

    public function test_retencao_dos_eventos_do_stripe_12_meses(): void
    {
        $velho = GatewayEvent::query()->create(['gateway' => 'stripe', 'event_id' => 'evt_velho', 'type' => 'invoice.paid', 'status' => 'processed', 'result' => 'applied',
            'payload' => '{"data":{"object":{"customer_email":"pessoa@exemplo.test"}}}', 'received_at' => now()->subMonths(13), 'processed_at' => now()->subMonths(13)]);
        $novo = GatewayEvent::query()->create(['gateway' => 'stripe', 'event_id' => 'evt_novo', 'type' => 'invoice.paid', 'status' => 'processed', 'result' => 'applied',
            'payload' => '{"x":1}', 'received_at' => now()->subMonths(11), 'processed_at' => now()->subMonths(11)]);

        $this->assertNotSame([], app(IntegrityChecker::class)->violations(), 'R51 acusa evento velho com corpo');
        $this->artisan('app:communication', ['task' => 'retention'])->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame(0, app(GatewayEventRetention::class)->run(), 'idempotente');

        $velho->refresh();
        $this->assertSame([null, 'evt_velho', 'processed', 'applied'], [$velho->payload, $velho->event_id, $velho->status, $velho->result]);
        $this->assertNotNull($velho->payload_purged_at);
        $this->assertSame('{"x":1}', $novo->refresh()->payload);
        $log = AuditLog::query()->where('action', 'retention.gateway_events')->sole();
        $this->assertStringNotContainsString('pessoa@exemplo.test', (string) json_encode($log->toArray()));
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }
}

<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\EnsureCustomerRecentlyConfirmed;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Area do cliente (Fase 12): cada cliente so ve e so mexe no que e dele.
 * Trocar o codigo na URL por um de outra pessoa da 404 (nao confirma que
 * existe) e nada muda; as listas partem do cliente logado; abrir uma tela
 * nunca altera consentimento.
 */
class CustomerAreaAccessTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    private Customer $intruso;

    private Appointment $futuro;

    private Attendance $atendido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Mail::fake();
        Notification::fake();

        $this->intruso = Customer::factory()->create(['name' => 'Intrusa Fictícia']);
        $this->atendido = $this->completedFor($this->cliente);
        $this->futuro = $this->book($this->terca, '10:00', customer: $this->cliente);
    }

    private function completedFor(Customer $customer, string $time = '10:00'): Attendance
    {
        $ag = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $customer,
        ));
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
    }

    /**
     * Toda rota da conta que recebe um registro na URL, com o pedido de quem
     * NAO e o dono. Uma rota nova com parametro precisa entrar aqui (o teste
     * abaixo confere a lista contra as rotas registradas).
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function protectedRoutes(): array
    {
        return [
            'account.appointments.show' => ['get', route('account.appointments.show', $this->futuro), []],
            'account.appointments.cancel' => ['post', route('account.appointments.cancel', $this->futuro), ['reason' => 'tentativa']],
            'account.appointments.reschedule' => ['get', route('account.appointments.reschedule', $this->futuro), []],
            'account.appointments.reschedule.update' => ['put', route('account.appointments.reschedule.update', $this->futuro), ['data' => $this->terca, 'hora' => '15:00', 'profissional' => $this->joao->slug]],
            'account.attendances.show' => ['get', route('account.attendances.show', $this->atendido), []],
            'account.attendances.print' => ['get', route('account.attendances.print', $this->atendido), []],
            'account.attendances.email' => ['post', route('account.attendances.email', $this->atendido), ['request_key' => '9b2f6c1e-3d4a-4b5c-8d9e-0f1a2b3c4d5e']],
            'account.reviews.create' => ['get', route('account.reviews.create', $this->atendido), []],
            'account.reviews.store' => ['post', route('account.reviews.store', $this->atendido), ['rating' => 1, 'comment' => 'tentativa']],
        ];
    }

    public function test_toda_rota_com_registro_na_url_esta_na_lista_de_conferencia(): void
    {
        $comParametro = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDef $r) => str_starts_with((string) $r->getName(), 'account.') && $r->parameterNames() !== [])
            ->reject(fn (RouteDef $r) => in_array($r->getName(), ['account.email.confirm.show', 'account.email.confirm'], true)) // token proprio (EmailChangeTest)
            ->map(fn (RouteDef $r) => (string) $r->getName())->sort()->values()->all();

        $this->assertSame(collect(array_keys($this->protectedRoutes()))->sort()->values()->all(), $comParametro);
    }

    public function test_registro_de_outro_cliente_responde_404_e_nada_muda(): void
    {
        $situacao = $this->futuro->status;
        foreach ($this->protectedRoutes() as $nome => [$metodo, $url, $dados]) {
            $this->actingAs($this->intruso, 'customer')->{$metodo}($url, $dados)->assertNotFound();
        }

        $this->assertSame($situacao, $this->futuro->fresh()->status);
        $this->assertNotSame(AppointmentStatus::Cancelled, $situacao);
        $this->assertTrue($this->futuro->fresh()->starts_at->eq($this->at($this->terca, '10:00')));
        $this->assertSame(0, Review::query()->count());
        Notification::assertNothingSent();
    }

    public function test_o_dono_acessa_os_proprios_registros(): void
    {
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', $this->futuro))->assertOk()->assertSee($this->futuro->code);
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.reschedule', $this->futuro))->assertOk();
        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.show', $this->atendido))->assertOk()->assertSee($this->atendido->code);
        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.print', $this->atendido))->assertOk();
        $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.create', $this->atendido))->assertOk();
    }

    public function test_urls_usam_codigo_e_nao_o_id_sequencial(): void
    {
        $this->assertStringNotContainsString('/'.$this->futuro->id, route('account.appointments.show', $this->futuro));
        $this->actingAs($this->cliente, 'customer')->get('/minha-conta/agendamentos/'.$this->futuro->id)->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->get('/minha-conta/atendimentos/'.$this->atendido->id)->assertNotFound();
    }

    public function test_listas_da_conta_mostram_so_o_proprio_cliente(): void
    {
        CustomerNotification::query()->create(['customer_id' => $this->cliente->id, 'kind' => 'reminder', 'message' => 'Aviso só da Cliente']);
        $this->activeSubscription($this->cliente);

        $telas = ['account.home', 'account.appointments.index', 'account.receipts.index', 'account.notifications', 'account.subscription',
            'account.loyalty', 'account.reviews.index', 'account.privacy', 'account.profile.edit'];
        foreach ($telas as $tela) {
            $r = $this->actingAs($this->intruso, 'customer')->get(route($tela));
            $this->assertSame(200, $r->status(), $tela.' -> '.$r->headers->get('Location'));
            $r
                ->assertDontSee($this->futuro->code)->assertDontSee($this->atendido->code)
                ->assertDontSee('Aviso só da Cliente')->assertDontSee('Cliente Fictício')->assertDontSee('Clube do Corte · Ativa');
        }

        $this->flushSession(); // outra pessoa no mesmo navegador (auth.session confere o hash da senha da sessao)
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.index'))->assertOk()->assertSee($this->futuro->code)->assertSee('R$ 50,00');
        $this->actingAs($this->cliente, 'customer')->get(route('account.receipts.index'))->assertOk()->assertSee($this->atendido->code);
        $this->actingAs($this->cliente, 'customer')->get(route('account.home'))->assertOk()->assertSee($this->futuro->code)->assertSee('Clube do Corte · Ativa');
    }

    public function test_assinatura_de_outra_pessoa_nao_e_alterada_pela_conta_alheia(): void
    {
        $s = $this->activeSubscription($this->cliente);

        // O intruso nao tem assinatura: as acoes partem da conta logada, nunca de um id enviado.
        $this->actingAs($this->intruso, 'customer')->post(route('account.subscription.cancel'), ['subscription' => $s->id])->assertNotFound();
        $this->actingAs($this->intruso, 'customer')->post(route('account.subscription.reactivate'), ['subscription' => $s->id])->assertNotFound();
        $this->assertSame(SubscriptionStatus::Active, $s->fresh()->status);
    }

    public function test_exportacao_traz_so_os_dados_do_proprio_cliente(): void
    {
        $doIntruso = $this->completedFor($this->intruso, '11:00');

        $r = $this->actingAs($this->cliente, 'customer')->withSession([EnsureCustomerRecentlyConfirmed::SESSION_KEY => time()])
            ->post(route('account.privacy.export'));
        $r->assertOk();
        $json = $r->streamedContent();

        $this->assertStringContainsString($this->atendido->code, $json);
        $this->assertStringContainsString($this->futuro->code, $json);
        $this->assertStringNotContainsString($doIntruso->code, $json);
        $this->assertStringNotContainsString('Intrusa', $json);
    }

    public function test_abrir_as_telas_nao_muda_consentimento_nem_preferencias(): void
    {
        $antes = [$this->cliente->marketing_email_consent, $this->cliente->email_reminders_enabled];
        $telas = ['account.home', 'account.appointments.index', 'account.receipts.index', 'account.notifications', 'account.subscription',
            'account.loyalty', 'account.reviews.index', 'account.privacy', 'account.profile.edit', 'account.password.edit'];
        foreach ($telas as $tela) {
            $this->actingAs($this->cliente, 'customer')->get(route($tela))->assertOk();
        }

        $c = $this->cliente->fresh();
        $this->assertSame($antes, [$c->marketing_email_consent, $c->email_reminders_enabled]);
        $this->assertSame(MarketingConsent::Unknown, $c->marketing_email_consent);
        $this->assertSame(0, ConsentRecord::query()->where('customer_id', $this->cliente->id)->count());
    }

    public function test_detalhe_mostra_preco_registrado_desconto_e_situacao(): void
    {
        $this->corte->forceFill(['price_cents' => 9000])->save(); // preco novo nao muda o que ja foi agendado

        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', $this->futuro))->assertOk()
            ->assertSee('R$ 50,00')->assertDontSee('R$ 90,00')->assertSee($this->futuro->status->label())->assertSee('data-change-rules', false);
    }

    public function test_cpf_nunca_aparece_inteiro_em_tela_da_conta(): void
    {
        $cpf = (string) $this->cliente->cpf;
        foreach (['account.home', 'account.profile.edit', 'account.privacy', 'account.appointments.index', 'account.receipts.index', 'account.subscription'] as $tela) {
            $this->actingAs($this->cliente, 'customer')->get(route($tela))->assertOk()->assertDontSee($cpf);
        }
    }
}

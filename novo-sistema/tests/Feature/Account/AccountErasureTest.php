<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\EnsureCustomerRecentlyConfirmed;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Customers\Services\CustomerErasure;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\SiteContent\Services\PublicSite;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SubscriptionFixtures;
use Tests\TestCase;

/**
 * Excluir a conta = anonimizar (R-33; E2E 15 da estrategia de testes): sai
 * todo dado pessoal, fica o financeiro sem o nome, avaliacoes anonimas,
 * prova do opt-out; bloqueia com horario marcado, atendimento aberto ou
 * assinatura vigente; pede a senha de novo e a palavra EXCLUIR.
 */
class AccountErasureTest extends TestCase
{
    use RefreshDatabase, SubscriptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSubscriptions();
        Mail::fake();
        Notification::fake();
        $this->cliente->forceFill(['password' => Hash::make('senha-ficticia-123'), 'phone' => '11988887777', 'birth_date' => '1990-03-10'])->save();
    }

    private function completed(Customer $customer, string $time = '10:00'): Attendance
    {
        $ag = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $customer, notes: 'Observação pessoal da cliente',
        ));
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($ag, $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, $this->pay(5000), $this->key(), $this->recepcao);
    }

    private function confirmed(): static
    {
        return $this->withSession([EnsureCustomerRecentlyConfirmed::SESSION_KEY => time()]);
    }

    public function test_cliente_exclui_a_conta_e_os_dados_pessoais_somem_mas_o_financeiro_fica(): void
    {
        $at = $this->completed($this->cliente);
        $review = app(Reviews::class)->submit($this->cliente, $at, 5, 'Atendimento excelente');
        $pago = Payment::query()->where('attendance_id', $at->id)->sum('amount_cents');
        CustomerNotification::query()->create(['customer_id' => $this->cliente->id, 'kind' => 'reminder', 'message' => 'Lembrete']);
        CustomerNote::query()->create(['customer_id' => $this->cliente->id, 'body' => 'Prefere máquina 2', 'visibility' => 'team']);
        $email = $this->cliente->email;
        $nome = $this->cliente->name;

        $this->actingAs($this->cliente, 'customer')->confirmed()
            ->delete(route('account.close.destroy'), ['confirmation' => 'EXCLUIR'])
            ->assertRedirect(route('home'));
        $this->assertGuest('customer');

        $c = Customer::query()->find($this->cliente->id);
        $this->assertSame(CustomerErasure::ANONYMIZED_NAME, $c->name);
        $this->assertNull($c->email);
        $this->assertNull($c->phone);
        $this->assertNull($c->cpf);
        $this->assertNull($c->birth_date);
        $this->assertNull($c->getAttribute('password'));
        $this->assertNull($c->referral_code);
        $this->assertNotNull($c->anonymized_at);
        $this->assertSame(CustomerStatus::Inactive, $c->status);
        $this->assertSame(MarketingConsent::Revoked, $c->marketing_email_consent);
        $this->assertFalse($c->canSignIn());

        // Financeiro e historico continuam, sem o nome.
        $at->refresh();
        $this->assertSame($c->id, $at->customer_id);
        $this->assertSame(CustomerErasure::ANONYMIZED_NAME, $at->customer_name);
        $this->assertSame($pago, Payment::query()->where('attendance_id', $at->id)->sum('amount_cents'));
        $this->assertSame(5000, (int) $at->total_cents);
        $ag = $at->appointment;
        $this->assertSame(CustomerErasure::ANONYMIZED_NAME, $ag->customer_name);
        $this->assertNull($ag->customer_email);
        $this->assertNull($ag->notes);

        // Avaliacao fica, anonima.
        $review->refresh();
        $this->assertSame(5, $review->rating);
        $this->assertSame('Cliente', PublicSite::reviewerName($review->load('customer')));

        // Apagados: avisos, anotacoes da equipe.
        $this->assertSame(0, CustomerNotification::query()->where('customer_id', $c->id)->count());
        $this->assertSame(0, CustomerNote::query()->where('customer_id', $c->id)->count());

        // Prova do opt-out, sem o endereco.
        $prova = ConsentRecord::query()->where('customer_id', $c->id)->where('source', 'account_erasure')->sole();
        $this->assertSame(['marketing_email', 'revoked', null], [$prova->purpose, $prova->action->value, $prova->email]);

        // Registro do pedido e auditoria sem dado pessoal.
        $this->assertSame('customer', DB::table('customer_erasures')->where('customer_id', $c->id)->value('requested_by'));
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.anonymized')->count());
        $trilha = AuditLog::query()->get()->map(fn (AuditLog $l) => json_encode([$l->old_values, $l->new_values, $l->actor_label, $l->description], JSON_UNESCAPED_UNICODE))->implode("\n");
        $this->assertStringNotContainsString((string) $email, $trilha);
        $this->assertStringNotContainsString($nome, $trilha);

        // Nada que identifique a pessoa sobra nas tabelas com texto livre de contato.
        foreach (['appointments' => ['customer_name', 'customer_email', 'customer_phone'], 'attendances' => ['customer_name', 'customer_phone'], 'email_messages' => ['to_email', 'to_name']] as $tabela => $colunas) {
            foreach ($colunas as $col) {
                $this->assertSame(0, DB::table($tabela)->whereIn($col, [$email, $nome, '+5511988887777'])->count(), "$tabela.$col");
            }
        }

        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_nao_entra_mais_com_os_dados_antigos(): void
    {
        $email = $this->cliente->email;
        app(CustomerErasure::class)->erase($this->cliente, $this->cliente);

        $this->post(route('customer.login.attempt'), ['email' => $email, 'password' => 'senha-ficticia-123'])->assertSessionHasErrors();
        $this->assertGuest('customer');
    }

    public function test_sessao_aberta_em_outro_aparelho_cai_na_proxima_requisicao(): void
    {
        $this->actingAs($this->cliente, 'customer')->get(route('account.home'))->assertOk();
        app(CustomerErasure::class)->erase($this->cliente, $this->cliente);

        // A sessao recarrega o cadastro do banco a cada requisicao (aqui, o registro atual).
        $this->actingAs(Customer::query()->findOrFail($this->cliente->id), 'customer')
            ->get(route('account.home'))->assertRedirect(route('customer.login'));
        $this->assertGuest('customer');
    }

    public function test_emails_na_fila_nao_saem_e_registros_ficam_sem_endereco(): void
    {
        $na = EmailMessage::query()->create([
            'public_id' => (string) str()->ulid(), 'category' => 'transactional', 'template' => 'booking_confirmed', 'to_email' => $this->cliente->email,
            'to_name' => $this->cliente->name, 'customer_id' => $this->cliente->id, 'subject' => 'Assunto com nome', 'params' => [], 'dedupe_key' => 'teste:1',
            'status' => MessageStatus::Queued, 'queued_at' => now(),
        ]);

        app(CustomerErasure::class)->erase($this->cliente, $this->cliente);

        $na->refresh();
        $this->assertSame(MessageStatus::Skipped, $na->status);
        $this->assertSame('[removido]', $na->to_email);
        $this->assertNull($na->to_name);
        $this->assertNull($na->subject);
    }

    public function test_bloqueia_com_horario_marcado_atendimento_aberto_ou_assinatura_vigente(): void
    {
        $ag = $this->book($this->terca, '10:00', customer: $this->cliente);
        $this->assertNotSame([], app(CustomerErasure::class)->blockers($this->cliente));
        $this->actingAs($this->cliente, 'customer')->confirmed()->get(route('account.close'))->assertOk()->assertSee('data-delete-blockers', false);
        $this->actingAs($this->cliente, 'customer')->confirmed()->delete(route('account.close.destroy'), ['confirmation' => 'EXCLUIR'])
            ->assertRedirect(route('account.close'));
        $this->assertNull($this->cliente->fresh()->anonymized_at);

        $this->booking()->cancel($ag, Channel::Staff, $this->recepcao, 'teste');
        $this->assertSame([], app(CustomerErasure::class)->blockers($this->cliente));

        $this->activeSubscription($this->cliente);
        $this->assertCount(1, app(CustomerErasure::class)->blockers($this->cliente));
        $this->expectException(DomainRuleViolation::class);
        app(CustomerErasure::class)->erase($this->cliente, $this->cliente);
    }

    public function test_pede_a_senha_de_novo_e_a_palavra_de_confirmacao(): void
    {
        $this->actingAs($this->cliente, 'customer')->get(route('account.close'))->assertRedirect(route('account.confirm.show'));
        $this->actingAs($this->cliente, 'customer')->delete(route('account.close.destroy'), ['confirmation' => 'EXCLUIR'])->assertRedirect(route('account.confirm.show'));
        $this->assertNull($this->cliente->fresh()->anonymized_at);

        $this->actingAs($this->cliente, 'customer')->post(route('account.confirm.store'), ['password' => 'errada-123'])->assertSessionHasErrors('password');
        $this->actingAs($this->cliente, 'customer')->post(route('account.confirm.store'), ['password' => 'senha-ficticia-123'])->assertRedirect();

        $this->actingAs($this->cliente, 'customer')->delete(route('account.close.destroy'), ['confirmation' => 'excluir'])->assertSessionHasErrors('confirmation');
        $this->assertNull($this->cliente->fresh()->anonymized_at);
    }

    public function test_volta_depois_da_senha_nunca_leva_para_fora_do_site(): void
    {
        $this->actingAs($this->cliente, 'customer')->withHeader('Referer', 'https://site-falso.exemplo/roubo')
            ->post(route('account.privacy.export'))->assertRedirect(route('account.confirm.show'));
        $this->actingAs($this->cliente, 'customer')->post(route('account.confirm.store'), ['password' => 'senha-ficticia-123'])
            ->assertRedirect(route('account.privacy'));
    }

    public function test_confirmacao_vence_depois_de_alguns_minutos(): void
    {
        $this->actingAs($this->cliente, 'customer')->withSession([EnsureCustomerRecentlyConfirmed::SESSION_KEY => time() - 16 * 60])
            ->get(route('account.close'))->assertRedirect(route('account.confirm.show'));
    }

    public function test_cliente_sem_senha_cria_uma_antes_das_acoes_sensiveis(): void
    {
        $this->cliente->forceFill(['password' => null])->save();

        $this->actingAs($this->cliente, 'customer')->get(route('account.confirm.show'))->assertOk()->assertSee('Crie uma senha primeiro');
        $this->actingAs($this->cliente, 'customer')->post(route('account.confirm.store'), ['password' => 'qualquer'])->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->put(route('account.password.update'), ['password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])->assertRedirect();
        $this->actingAs($this->cliente, 'customer')->get(route('account.close'))->assertOk();
    }

    public function test_equipe_anonimiza_so_com_a_permissao_e_fica_registrado_quem_fez(): void
    {
        $dono = User::factory()->owner()->create();
        $this->assertTrue($dono->can('anonymize', $this->cliente));
        $this->assertFalse($this->recepcao->can('anonymize', $this->cliente));

        app(CustomerErasure::class)->erase($this->cliente, $dono);
        $this->assertSame(['staff', $dono->id], [DB::table('customer_erasures')->value('requested_by'), DB::table('customer_erasures')->value('staff_user_id')]);
    }

    public function test_e_idempotente(): void
    {
        $this->assertNotSame([], app(CustomerErasure::class)->erase($this->cliente, $this->cliente));
        $this->assertSame([], app(CustomerErasure::class)->erase($this->cliente->fresh(), $this->cliente));
        $this->assertSame(1, DB::table('customer_erasures')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.anonymized')->count());
    }

    public function test_quem_foi_indicado_pela_pessoa_excluida_continua_com_o_proprio_cadastro(): void
    {
        $amigo = Customer::factory()->create(['referred_by_customer_id' => $this->cliente->id]);
        app(CustomerErasure::class)->erase($this->cliente, $this->cliente);

        $this->assertSame($this->cliente->id, $amigo->fresh()->referred_by_customer_id);
        $this->assertNotNull($amigo->fresh()->email);
    }
}

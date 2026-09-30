<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\CancelledBy;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentItem;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * As unicas regras de criar, remarcar, cancelar e mudar status.
 */
class BookingServiceTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    // --- Criacao, preco e snapshot -------------------------------------------------------------

    public function test_cria_com_preco_duracao_e_nomes_congelados(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame('14:00', $a->starts_at->timezone('America/Sao_Paulo')->format('H:i'));
        $this->assertSame('14:30', $a->ends_at->timezone('America/Sao_Paulo')->format('H:i'), 'fim = início + duração do serviço');
        $this->assertSame('João', $a->professional_name);
        $this->assertSame('Cliente Fictício', $a->customer_name);
        $this->assertSame(5000, $a->total_cents);

        $item = $a->items()->sole();
        $this->assertSame(['Corte', 5000, 5000, 30, PriceSource::CatalogAtBooking],
            [$item->name, $item->unit_price_cents, $item->total_cents, $item->duration_minutes, $item->price_source]);
    }

    public function test_mudar_o_catalogo_depois_nao_altera_o_agendamento(): void
    {
        $a = $this->book($this->terca, '14:00');

        $this->corte->update(['price_cents' => 6000, 'duration_minutes' => 60, 'name' => 'Corte premium']);
        $this->joao->update(['display_name' => 'João Navalha']);

        $a->refresh();
        $item = $a->items()->sole();
        $this->assertSame(5000, $a->total_cents);
        $this->assertSame([5000, 30, 'Corte'], [$item->unit_price_cents, $item->duration_minutes, $item->name]);
        $this->assertSame('João', $a->professional_name);
        $this->assertSame('14:30', $a->ends_at->timezone('America/Sao_Paulo')->format('H:i'));
    }

    public function test_horario_indisponivel_nao_grava_nada(): void
    {
        $this->book($this->terca, '14:00');

        try {
            $this->book($this->terca, '14:15');
            $this->fail('reservou horário ocupado');
        } catch (SlotUnavailable $e) {
            $this->assertTrue($e->isConflict());
        }

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(1, AppointmentItem::query()->count());
    }

    public function test_sem_preferencia_escolhe_quem_esta_livre(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria', 'sort_order' => 99]);
        $maria->services()->attach($this->corte->id);
        $this->book($this->terca, '14:00'); // João

        $a = $this->booking()->book(new BookingRequest($this->corte, null, $this->at($this->terca, '14:00'), Channel::Customer, AppointmentSource::Online, $this->cliente));

        $this->assertSame($maria->id, $a->professional_id);
    }

    public function test_confirmacao_manual_quando_a_barbearia_exige(): void
    {
        BookingPolicy::save(['requires_confirmation' => true] + BookingPolicy::current()->toArray());

        $site = $this->book($this->terca, '14:00');
        $balcao = $this->book($this->terca, '15:00', channel: Channel::Staff);
        $this->assertSame(AppointmentStatus::Pending, $site->status);
        $this->assertSame(AppointmentStatus::Confirmed, $balcao->status, 'a equipe já confirma ao criar');

        $confirmado = $this->booking()->confirm($site, User::factory()->create());
        $this->assertSame(AppointmentStatus::Confirmed, $confirmado->status);
        $this->assertNotNull($confirmado->confirmed_at);
        $this->assertSame(AppointmentStatus::Confirmed, $site->fresh()->status);
    }

    public function test_cliente_nao_fica_em_dois_lugares(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria']);
        $maria->services()->attach($this->corte->id);
        $this->book($this->terca, '14:00', customer: $this->cliente);

        $this->expectException(BookingRuleViolation::class);
        $this->book($this->terca, '14:15', $maria, customer: $this->cliente);
    }

    public function test_cliente_sem_cpf_nao_agenda_pelo_site(): void
    {
        $semCpf = Customer::factory()->withoutCpf()->create();

        $this->expectException(BookingRuleViolation::class);
        $this->book($this->terca, '14:00', customer: $semCpf);
    }

    public function test_balcao_aceita_cliente_sem_cadastro_so_com_nome(): void
    {
        $a = $this->booking()->book(new BookingRequest($this->corte, $this->joao, $this->at($this->segunda, '09:00'), Channel::Staff, AppointmentSource::Staff,
            contactName: 'Passante', contactPhone: '(11) 90000-0000', actor: $u = User::factory()->create()));

        $this->assertNull($a->customer_id);
        $this->assertSame('Passante', $a->customer_name);
        $this->assertSame($u->id, $a->created_by_user_id);

        $this->expectException(BookingRuleViolation::class);
        $this->booking()->book(new BookingRequest($this->corte, $this->joao, $this->at($this->segunda, '10:00'), Channel::Staff, AppointmentSource::Staff));
    }

    // --- Status ---------------------------------------------------------------------------------

    public function test_transicoes_de_status(): void
    {
        $u = User::factory()->create();
        $a = $this->book($this->segunda, '10:00');

        try {
            $this->booking()->markNoShow($a, $u);
            $this->fail('falta antes do horário');
        } catch (BookingRuleViolation $e) {
            $this->assertSame('not_started', $e->reason);
        }

        $this->travelTo($this->at($this->segunda, '10:40'));
        $falta = $this->booking()->markNoShow($a, $u);
        $this->assertSame(AppointmentStatus::NoShow, $falta->status);

        $this->expectException(BookingRuleViolation::class);
        $this->booking()->cancel($falta, Channel::Staff, $u); // no_show nao volta para cancelado
    }

    // --- Cancelamento --------------------------------------------------------------------------

    public function test_cancelar_preserva_o_registro_e_libera_o_horario(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $c = $this->booking()->cancel($a, Channel::Customer, $this->cliente, 'Imprevisto');

        $this->assertSame(AppointmentStatus::Cancelled, $c->status);
        $this->assertSame(CancelledBy::Customer, $c->cancelled_by);
        $this->assertSame('Imprevisto', $c->cancellation_reason);
        $this->assertNotNull($c->cancelled_at);
        $this->assertSame(5000, $c->total_cents, 'valores ficam');
        $this->assertNull($this->reasonAt($this->terca, '14:00'));
        $this->assertSame(['created', 'cancelled'], $c->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_prazo_de_cancelamento_do_cliente(): void
    {
        $a = $this->book($this->segunda, '11:00', customer: $this->cliente); // agora: 08:00

        $this->travelTo($this->at($this->segunda, '09:01')); // menos de 120 min antes
        try {
            $this->booking()->cancel($a, Channel::Customer, $this->cliente);
            $this->fail('cancelou fora do prazo');
        } catch (BookingRuleViolation $e) {
            $this->assertSame('cancel_deadline', $e->reason);
        }

        // A equipe ainda cancela.
        $this->assertSame(AppointmentStatus::Cancelled, $this->booking()->cancel($a, Channel::Staff, User::factory()->create())->status);
    }

    public function test_cancelado_nao_cancela_de_novo(): void
    {
        $a = $this->booking()->cancel($this->book($this->terca, '14:00'), Channel::Staff, null);

        $this->expectException(BookingRuleViolation::class);
        $this->booking()->cancel($a, Channel::Staff, null);
    }

    // --- Remarcacao ----------------------------------------------------------------------------

    public function test_remarcar_move_horario_mantem_preco_e_registra_historico(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);
        $this->corte->update(['price_cents' => 9900, 'duration_minutes' => 60]);

        $r = $this->booking()->reschedule($a, $this->at($this->terca, '16:00'), null, Channel::Customer, $this->cliente);

        $this->assertSame('16:00', $r->starts_at->timezone('America/Sao_Paulo')->format('H:i'));
        $this->assertSame('16:30', $r->ends_at->timezone('America/Sao_Paulo')->format('H:i'), 'duração congelada (30), não a nova (60)');
        $this->assertSame(5000, $r->fresh()->total_cents, 'preço congelado');
        $this->assertSame($a->id, $r->id, 'mesmo agendamento, sem duplicar');
        $this->assertSame(1, $r->customer_reschedules);
        $this->assertNull($this->reasonAt($this->terca, '14:00'), 'horário antigo liberado');

        $evento = $r->events()->where('type', 'rescheduled')->sole();
        $this->assertStringContainsString('06/10/2026 14:00', $evento->data['de']);
        $this->assertStringContainsString('06/10/2026 16:00', $evento->data['para']);

        $log = AuditLog::query()->where('auditable_type', 'Appointment')->where('auditable_id', $a->id)->where('action', 'updated')->latest('id')->first();
        $this->assertArrayHasKey('starts_at', $log->old_values, 'antes e depois na auditoria');
    }

    public function test_remarcar_para_outro_profissional(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria']);
        $maria->services()->attach($this->corte->id);
        $a = $this->book($this->terca, '14:00');

        $r = $this->booking()->reschedule($a, $this->at($this->terca, '14:00'), $maria, Channel::Staff, User::factory()->create());

        $this->assertSame($maria->id, $r->professional_id);
        $this->assertSame('Maria', $r->professional_name);
        $this->assertSame(0, $r->customer_reschedules, 'equipe não conta no limite do cliente');
    }

    public function test_remarcar_valida_disponibilidade_ignorando_o_proprio(): void
    {
        $a = $this->book($this->terca, '14:00');
        $this->book($this->terca, '15:00');

        // 14:15 sobrepoe o proprio (ignorado) mas esta livre: pode.
        $r = $this->booking()->reschedule($a, $this->at($this->terca, '14:15'), null, Channel::Staff, null);
        $this->assertSame('14:15', $r->starts_at->timezone('America/Sao_Paulo')->format('H:i'));

        $this->expectException(SlotUnavailable::class);
        $this->booking()->reschedule($a, $this->at($this->terca, '14:45'), null, Channel::Staff, null); // bate com 15:00
    }

    public function test_limites_do_cliente_na_remarcacao(): void
    {
        BookingPolicy::save(['customer_max_reschedules' => 1] + BookingPolicy::current()->toArray());
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $a = $this->booking()->reschedule($a, $this->at($this->terca, '15:00'), null, Channel::Customer, $this->cliente);

        try {
            $this->booking()->reschedule($a, $this->at($this->terca, '16:00'), null, Channel::Customer, $this->cliente);
            $this->fail('passou do limite');
        } catch (BookingRuleViolation $e) {
            $this->assertSame('reschedule_limit', $e->reason);
        }

        try {
            $this->booking()->reschedule($a, $this->at($this->terca, '15:00'), null, Channel::Staff, null);
            $this->fail('remarcou para o mesmo horário');
        } catch (BookingRuleViolation $e) {
            $this->assertSame('no_change', $e->reason);
        }

        $this->travelTo($this->at($this->terca, '14:00'));
        try {
            $this->booking()->reschedule($a->fresh(), $this->at($this->terca, '18:00'), null, Channel::Staff, null);
        } catch (BookingRuleViolation) {
            $this->fail('a equipe não tem o limite do cliente');
        }
    }

    public function test_prazo_de_remarcacao_do_cliente(): void
    {
        $a = $this->book($this->segunda, '11:00', customer: $this->cliente);
        $this->travelTo($this->at($this->segunda, '09:30'));

        $this->expectException(BookingRuleViolation::class);
        $this->booking()->reschedule($a, $this->at($this->segunda, '15:00'), null, Channel::Customer, $this->cliente);
    }

    public function test_cancelado_nao_pode_ser_remarcado(): void
    {
        $a = $this->booking()->cancel($this->book($this->terca, '14:00'), Channel::Staff, null);

        $this->expectException(BookingRuleViolation::class);
        $this->booking()->reschedule($a, $this->at($this->terca, '16:00'), null, Channel::Staff, null);
    }

    public function test_desativar_servico_ou_profissional_nao_mexe_no_agendamento(): void
    {
        $a = $this->book($this->terca, '14:00');

        $this->corte->update(['is_active' => false]);
        $this->joao->update(['is_active' => false]);

        $a->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame($this->joao->id, $a->professional_id);
        $this->assertSame(5000, $a->total_cents);
        $r = $this->availability()->check($this->corte, $this->joao->fresh(), $this->at('2026-10-07', '10:00'), Channel::Customer);
        $this->assertTrue($r->has('service_unavailable') && $r->has('professional_unavailable'), 'mas não recebe novos');
    }

    public function test_criacao_e_auditada_sem_contato_do_cliente(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $log = AuditLog::query()->where('auditable_type', 'Appointment')->where('auditable_id', $a->id)->where('action', 'created')->sole();
        $this->assertArrayNotHasKey('customer_email', $log->new_values);
        $this->assertArrayNotHasKey('customer_phone', $log->new_values);
        $this->assertSame('created', $a->events()->sole()->type);
    }
}

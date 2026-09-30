<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgendaFixtures;
use Tests\TestCase;

/**
 * Jornada do cliente pelo site e pela conta, e tudo o que ele NAO pode fazer
 * manipulando a URL ou o formulario.
 */
class CustomerBookingFlowTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    /**
     * @return array<string, string>
     */
    private function escolha(array $extra = []): array
    {
        return $extra + ['servico' => $this->corte->slug, 'profissional' => $this->joao->slug, 'data' => $this->terca, 'hora' => '14:00'];
    }

    public function test_ve_servicos_profissionais_e_horarios_sem_login(): void
    {
        $this->get(route('booking.services'))->assertOk()->assertSee('Corte')->assertSee('R$ 50,00');
        $this->get(route('booking.professional', $this->corte))->assertOk()->assertSee('João')->assertSee('Sem preferência');

        $html = $this->get(route('booking.slots', ['service' => $this->corte, 'profissional' => $this->joao->slug, 'data' => $this->terca]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('hora=09%3A00', $html, 'primeiro horário');
        $this->assertStringContainsString('hora=19%3A30', $html, 'último horário vem do servidor');
        $this->assertStringNotContainsString('hora=19%3A45', $html);
    }

    public function test_telas_do_cliente_sem_estilo_ou_handler_inline(): void
    {
        $a = $this->book($this->terca, '16:00', customer: $this->cliente);
        $publicas = [route('booking.services'), route('booking.professional', $this->corte), route('booking.slots', ['service' => $this->corte, 'profissional' => 'qualquer'])];
        $conta = [route('account.booking.confirm', $this->escolha()), route('account.appointments.show', $a), route('account.appointments.reschedule', $a)];

        foreach ($publicas as $url) {
            $this->assertDoesNotMatchRegularExpression('/sstyle="|son(click|change|submit|load|input)=/i', $this->get($url)->assertOk()->getContent(), $url);
        }
        foreach ($conta as $url) {
            $this->assertDoesNotMatchRegularExpression('/sstyle="|son(click|change|submit|load|input)=/i', $this->actingAs($this->cliente, 'customer')->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_servico_inativo_ou_profissional_que_nao_faz_nao_aparecem(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria']); // nao faz Corte

        $this->get(route('booking.slots', ['service' => $this->corte, 'profissional' => $maria->slug]))->assertNotFound();
        $this->get(route('booking.slots', ['service' => $this->corte, 'profissional' => 'inventado']))->assertNotFound();

        $this->corte->update(['is_active' => false]);
        $this->get(route('booking.professional', $this->corte))->assertNotFound();
        $this->get(route('booking.services'))->assertDontSee('Corte');
    }

    public function test_confirmar_exige_login_e_depois_agenda(): void
    {
        $this->get(route('account.booking.confirm', $this->escolha()))->assertRedirect(route('customer.login'));

        $this->actingAs($this->cliente, 'customer')->get(route('account.booking.confirm', $this->escolha()))
            ->assertOk()->assertSee('Confirme seu horário')->assertSee('R$ 50,00')->assertSee('14:00 às 14:30');

        $r = $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['notes' => 'Primeira vez']));

        $a = Appointment::query()->sole();
        $r->assertRedirect(route('account.appointments.show', $a));
        $this->assertSame($this->cliente->id, $a->customer_id);
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame('Primeira vez', $a->notes);
        $this->assertSame(5000, $a->total_cents);
    }

    public function test_sem_preferencia_reserva_com_quem_esta_livre(): void
    {
        $maria = Professional::factory()->create(['display_name' => 'Maria', 'sort_order' => 99]);
        $maria->services()->attach($this->corte->id);
        $this->book($this->terca, '14:00');

        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['profissional' => 'qualquer']));

        $this->assertSame($maria->id, Appointment::query()->where('customer_id', $this->cliente->id)->sole()->professional_id);
    }

    public function test_horario_ocupado_ou_manipulado_nao_reserva(): void
    {
        $this->book($this->terca, '14:00');
        $barba = Service::factory()->create(['name' => 'Barba']); // João nao faz

        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha())
            ->assertRedirect()->assertSessionHasErrors('slot');
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['hora' => '03:00']))
            ->assertSessionHasErrors('slot');
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['hora' => '14:07']))
            ->assertSessionHasErrors('slot');
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['data' => '2020-01-01']))
            ->assertSessionHasErrors('slot');
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['hora' => '25:00']))->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->post(route('account.booking.store'), $this->escolha(['servico' => $barba->slug]))->assertNotFound();

        $this->assertSame(0, Appointment::query()->where('customer_id', $this->cliente->id)->count());
    }

    public function test_cliente_sem_cpf_completa_o_cadastro_antes(): void
    {
        $semCpf = Customer::factory()->withoutCpf()->create();

        $this->actingAs($semCpf, 'customer')->get(route('account.booking.confirm', $this->escolha()))->assertRedirect(route('account.complete.edit'));
        $this->actingAs($semCpf, 'customer')->post(route('account.booking.store'), $this->escolha())->assertRedirect(route('account.complete.edit'));
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_cancela_e_remarca_o_proprio_pela_conta(): void
    {
        $a = $this->book($this->terca, '14:00', customer: $this->cliente);

        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', $a))->assertOk()->assertSee('Remarcar')->assertSee('Cancelar agendamento');
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.reschedule', ['appointment' => $a, 'data' => $this->terca]))->assertOk()->assertSee('value="16:00"', false);

        $this->actingAs($this->cliente, 'customer')->put(route('account.appointments.reschedule.update', $a), ['data' => $this->terca, 'hora' => '16:00', 'profissional' => $this->joao->slug])
            ->assertRedirect(route('account.appointments.show', $a));
        $this->assertSame('19:00', $a->fresh()->starts_at->format('H:i'), '16:00 em Sao Paulo = 19:00 UTC');

        $this->actingAs($this->cliente, 'customer')->post(route('account.appointments.cancel', $a))->assertRedirect();
        $this->assertSame(AppointmentStatus::Cancelled, $a->fresh()->status);
    }

    public function test_nao_mexe_no_agendamento_de_outro_cliente(): void
    {
        $alheio = $this->book($this->terca, '14:00');

        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.show', $alheio))->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->get(route('account.appointments.reschedule', $alheio))->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->put(route('account.appointments.reschedule.update', $alheio), ['data' => $this->terca, 'hora' => '16:00', 'profissional' => $this->joao->slug])->assertNotFound();
        $this->actingAs($this->cliente, 'customer')->post(route('account.appointments.cancel', $alheio))->assertNotFound();

        $this->assertSame(AppointmentStatus::Confirmed, $alheio->fresh()->status);
        $this->assertSame('17:00', $alheio->fresh()->starts_at->format('H:i'));
    }

    public function test_prazo_vencido_mostra_a_regra_e_nao_altera(): void
    {
        $a = $this->book($this->segunda, '11:00', customer: $this->cliente);
        $this->travelTo($this->at($this->segunda, '09:30'));

        $this->actingAs($this->cliente, 'customer')->post(route('account.appointments.cancel', $a))
            ->assertSessionHasErrors(['appointment' => 'O prazo para cancelar por aqui já passou. Fale com a barbearia.']);
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
    }

    public function test_sessao_da_equipe_nao_agenda_como_cliente(): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web')
            ->post(route('account.booking.store'), $this->escolha())->assertRedirect(route('customer.login'));
        $this->assertSame(0, Appointment::query()->count());
    }
}

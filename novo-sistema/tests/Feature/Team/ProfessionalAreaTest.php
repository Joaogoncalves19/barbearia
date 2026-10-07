<?php

namespace Tests\Feature\Team;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Enums\NoteVisibility;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Services\CustomerNotes;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingRequest;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\SiteContent\Support\Appearance;
use App\Modules\SiteContent\Support\Theme;
use App\Modules\System\Models\AuditLog;
use App\Modules\Team\Enums\TimeOffKind;
use App\Modules\Team\Models\BlockedSlot;
use App\Modules\Team\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Area do profissional (Fase 12.5, painel-profissional.md): entrada,
 * telas, permissoes, isolamento entre profissionais (inclusive trocando ids
 * na URL), negacao do painel administrativo, fluxo do atendimento pelas
 * regras atuais, anotacoes do cliente, tempo livre pela regra da agenda e
 * temas. Relogio: segunda 05/10/2026 08:00 (AgendaFixtures).
 */
class ProfessionalAreaTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    private User $barbeiroJoao;

    private User $barbeiraMaria;

    private Professional $maria;

    private Customer $clienteDaMaria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->barbeiroJoao = User::factory()->role(StaffRole::Professional)->create(['name' => 'João Barbeiro', 'email' => 'joao.barbeiro@exemplo.test']);
        $this->joao->update(['user_id' => $this->barbeiroJoao->id]);
        $this->barbeiraMaria = User::factory()->role(StaffRole::Professional)->create(['name' => 'Maria Barbeira']);
        $this->maria = Professional::factory()->create(['display_name' => 'Maria', 'user_id' => $this->barbeiraMaria->id]);
        $this->maria->services()->attach([$this->corte->id, $this->barba->id]);
        $this->clienteDaMaria = Customer::factory()->create(['name' => 'Cliente da Maria']);
        $this->percent(5000);
    }

    private function as(User $u): static
    {
        return $this->actingAs($u, 'web');
    }

    /** Agendamento de hoje com a Maria, para a cliente dela. */
    private function mariaToday(string $time = '11:00'): Appointment
    {
        return $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->maria, start: $this->at($this->segunda, $time),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $this->clienteDaMaria,
        ));
    }

    // --- Entrada e telas --------------------------------------------------------------------

    public function test_login_do_profissional_cai_na_area_dele(): void
    {
        $this->get('/profissional/entrar')->assertRedirect('/painel/entrar');

        $this->post(route('staff.login.attempt'), ['identifier' => 'joao.barbeiro@exemplo.test', 'password' => 'password'])
            ->assertRedirect(route('panel.home'));
        $this->get(route('panel.home'))->assertRedirect(route('pro.today'));
        $this->get(route('pro.today'))->assertOk()->assertSee('Seu dia')->assertSee('Área do profissional')
            ->assertDontSee('id="menu-painel"', false);
    }

    public function test_telas_da_area_mostram_so_o_proprio_dia(): void
    {
        $at = $this->startedAttendance();
        $proximo = $this->booking()->book(new BookingRequest(
            service: $this->corte, professional: $this->joao, start: $this->at($this->segunda, '14:00'),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: Customer::factory()->create(['name' => 'Próximo Fictício']),
        ));
        $daMaria = $this->mariaToday();

        $this->as($this->barbeiroJoao)->get(route('pro.today'))->assertOk()
            ->assertSee('Atendendo agora')->assertSee('Cliente Fictício')->assertSee('Finalizar')
            ->assertSee('Próximo Fictício')->assertSee('14:00')
            ->assertDontSee('Cliente da Maria');

        $this->as($this->barbeiroJoao)->get(route('pro.agenda'))->assertOk()
            ->assertSee('Cliente Fictício')->assertSee('Próximo Fictício')->assertSee('Livre')
            ->assertDontSee('Cliente da Maria')->assertDontSee(route('pro.appointments.show', $daMaria));

        $this->as($this->barbeiroJoao)->get(route('pro.appointments.show', $proximo))->assertOk()->assertSee('Próximo Fictício')->assertSee('Primeira vez com você');
        $this->as($this->barbeiroJoao)->get(route('pro.attendances'))->assertOk()->assertSee(route('pro.attendances.show', $at));
        $this->as($this->barbeiroJoao)->get(route('pro.attendances.show', $at))->assertOk()->assertSee('Finalizar e receber')->assertSee('R$ 50,00');
        $this->as($this->barbeiroJoao)->get(route('pro.earnings'))->assertOk()->assertSee('A receber')->assertSee('Sua comissão hoje')->assertSee('50%');
        $this->as($this->barbeiroJoao)->get(route('pro.profile'))->assertOk()->assertSee('João')->assertSee('joao.barbeiro@exemplo.test')->assertSee('Corte');
    }

    public function test_dia_sem_atendimento_mostra_o_estado_vazio_e_o_proximo(): void
    {
        $this->todayAppointment('15:00');

        $this->as($this->barbeiroJoao)->get(route('pro.today'))->assertOk()
            ->assertSee('Você não tem atendimentos agora.')->assertSee('Próximo às');
    }

    // --- Permissoes -------------------------------------------------------------------------

    public function test_so_o_profissional_com_ficha_entra_na_area(): void
    {
        $urls = [route('pro.today'), route('pro.agenda'), route('pro.attendances'), route('pro.earnings'), route('pro.profile')];
        $outros = [
            'dono' => $this->dono,
            'gerente' => User::factory()->manager()->create(),
            'recepção' => $this->recepcao,
            'financeiro' => $this->financeiro,
            'profissional sem ficha' => User::factory()->role(StaffRole::Professional)->create(),
        ];
        foreach ($outros as $quem => $u) {
            foreach ($urls as $url) {
                $this->as($u)->get($url)->assertForbidden();
            }
            $this->assertFalse($u->fresh()->can('professional_area.access') && $u->fresh()->professional !== null, $quem);
        }

        auth('web')->logout();
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('staff.login'));
        }
        $this->actingAs($this->cliente, 'customer')->get(route('pro.today'))->assertRedirect(route('staff.login'));
    }

    public function test_profissional_nao_acessa_o_painel_administrativo(): void
    {
        $at = $this->startedAttendance();
        foreach (['/painel/caixa', '/painel/usuarios', '/painel/servicos', '/painel/produtos', '/painel/comissoes', '/painel/repasses',
            '/painel/comissoes/regras', '/painel/aparencia', '/painel/auditoria', '/painel/agenda/configuracoes', '/painel/bloqueios',
            '/painel/cupons', '/painel/assinaturas', '/painel/campanhas'] as $url) {
            $this->as($this->barbeiroJoao)->get($url)->assertForbidden();
        }

        // Pedidos montados a mao: desconto, ajuste, repasse e vale continuam negados.
        $this->as($this->barbeiroJoao)->post(route('panel.attendances.discount.store', $at), ['discount_type' => 'fixed', 'discount_value' => '10,00', 'discount_reason' => 'Tentativa'])->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.commissions.adjust', $this->joao), ['request_key' => $this->key(), 'ledger' => 'commission', 'direction' => 'credit', 'amount' => '100,00', 'reason' => 'Tentativa'])->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.payouts.store', $this->joao), ['request_key' => $this->key(), 'method' => 'cash'])->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.advances.store', $this->joao), ['request_key' => $this->key(), 'amount' => '50,00', 'method' => 'cash', 'description' => 'Tentativa'])->assertForbidden();
        $this->as($this->barbeiroJoao)->post(route('panel.commission-rules.store'), ['target' => 'service', 'type' => 'percent', 'rate' => '90'])->assertForbidden();
        $this->assertSame(0, $at->discounts()->count());
        $this->assertSame(0, CommissionEntry::query()->count());
    }

    public function test_isolamento_entre_profissionais_trocando_ids_na_url(): void
    {
        $meu = $this->completedAttendance($this->pay(5000, PaymentMethod::Pix, 700));
        $daMariaAg = $this->mariaToday();
        $daMaria = $this->attendances()->start($this->attendances()->openFromAppointment($daMariaAg, $this->recepcao), $this->recepcao);
        $daMaria = $this->finish($daMaria);
        $repasseDaMaria = $this->payouts()->pay($this->maria, PaymentMethod::Cash, null, $this->dono, $this->key());
        $outraDaMaria = $this->walkIn($this->maria, $this->barba, 'Encaixe da Maria');

        $j = $this->as($this->barbeiroJoao);
        $j->get(route('pro.appointments.show', $daMariaAg))->assertNotFound();
        $j->get(route('pro.attendances.show', $daMaria))->assertNotFound();
        $j->get(route('pro.attendances.show', $outraDaMaria))->assertNotFound();
        $j->get('/profissional/atendimentos/999999')->assertNotFound();
        $j->get(route('panel.appointments.show', $daMariaAg))->assertNotFound();
        $j->get(route('panel.commissions.show', $this->maria))->assertNotFound();
        $j->get(route('panel.payouts.show', $repasseDaMaria))->assertNotFound();
        $j->get(route('panel.receipts.payout', $repasseDaMaria))->assertNotFound();
        $j->get(route('panel.receipts.attendance', $daMaria))->assertNotFound();

        // Filtro de outro profissional na URL nao muda nada.
        $j->get(route('pro.agenda', ['profissional' => $this->maria->id]))->assertOk()->assertDontSee('Cliente da Maria');
        $j->get(route('pro.attendances', ['busca' => 'Maria']))->assertOk()->assertDontSee('Encaixe da Maria')->assertDontSee('Cliente da Maria');
        $j->get(route('pro.earnings', ['profissional' => $this->maria->id]))->assertOk()
            ->assertSee('Cliente Fictício')->assertDontSee('Cliente da Maria')->assertSee('R$ 7,00');

        // Gravar no atendimento da Maria: negado e nada muda.
        $this->assertContains($j->post(route('panel.attendances.start', $outraDaMaria))->status(), [403, 404]);
        $this->assertContains($j->post(route('panel.attendances.services.store', $outraDaMaria), ['service_id' => $this->corte->id])->status(), [403, 404]);
        $this->assertContains($j->post(route('panel.attendances.complete', $outraDaMaria), ['completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => '30,00']]])->status(), [403, 404]);
        $this->assertSame(AttendanceStatus::Open, $outraDaMaria->fresh()->status);
        $this->assertSame(1, $outraDaMaria->items()->count());
        $this->assertSame(AttendanceStatus::Completed, $meu->fresh()->status);
    }

    // --- Paginas do painel levam para a area ---------------------------------------------------

    public function test_paginas_do_painel_com_equivalente_levam_para_a_area(): void
    {
        $at = $this->startedAttendance();
        $ag = $at->appointment;
        $j = $this->as($this->barbeiroJoao);

        $j->get(route('panel.home'))->assertRedirect(route('pro.today'));
        $j->get(route('panel.agenda', ['data' => $this->terca]))->assertRedirect(route('pro.agenda', ['data' => $this->terca]));
        $j->get(route('panel.appointments.show', $ag))->assertRedirect(route('pro.appointments.show', $ag));
        $j->get(route('panel.attendances.index'))->assertRedirect(route('pro.attendances'));
        $j->get(route('panel.attendances.show', $at))->assertRedirect(route('pro.attendances.show', $at));
        $j->get(route('panel.commissions.mine'))->assertRedirect(route('pro.earnings'));
        $j->get(route('panel.account.edit'))->assertRedirect(route('pro.profile'));
        $j->get(route('panel.professionals.show', $this->joao))->assertRedirect(route('pro.profile'));

        // Formularios que ja existiam abrem na moldura do profissional (sem o menu administrativo).
        $j->get(route('panel.appointments.create'))->assertOk()->assertSee('Área do profissional')->assertDontSee('id="menu-painel"', false)->assertDontSee('Clientes e vendas');
        $j->get(route('panel.password.edit'))->assertOk()->assertSee('Área do profissional');

        // A mensagem da acao sobrevive ao redirecionamento.
        $j->followingRedirects()->put(route('panel.account.update'), ['name' => 'João da Navalha'])->assertOk()->assertSee('Nome atualizado.')->assertSee('João da Navalha');

        // Outros papeis continuam no painel.
        $this->as($this->recepcao)->get(route('panel.home'))->assertOk()->assertSee('id="menu-painel"', false);
    }

    // --- Atendimento pelas regras atuais --------------------------------------------------------

    public function test_fluxo_do_atendimento_pela_area_usa_o_servico_atual(): void
    {
        $ag = $this->todayAppointment();
        $j = $this->as($this->barbeiroJoao);

        $j->from(route('pro.appointments.show', $ag))->post(route('panel.attendances.open', $ag))->assertRedirect();
        $at = Attendance::query()->sole();
        $j->get(route('panel.attendances.show', $at))->assertRedirect(route('pro.attendances.show', $at));

        $voltar = route('pro.attendances.show', $at);
        $j->from($voltar)->post(route('panel.attendances.start', $at))->assertRedirect($voltar);
        $j->from($voltar)->post(route('panel.attendances.services.store', $at), ['service_id' => $this->barba->id])->assertRedirect($voltar)->assertSessionHasNoErrors();
        $j->from($voltar)->post(route('panel.attendances.products.store', $at), ['product_id' => $this->pomada->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $j->from($voltar)->put(route('panel.attendances.notes', $at), ['notes' => 'Degradê na 1'])->assertSessionHasNoErrors();
        $j->get($voltar)->assertOk()->assertSee('R$ 115,00')->assertSee('Barba')->assertSee('Pomada modeladora')->assertSee('Degradê na 1');

        // Valor adulterado: nao fecha com o total, nada e gravado.
        $j->from($voltar)->post(route('panel.attendances.complete', $at), ['completion_key' => $this->key(), 'payments' => [['method' => 'pix', 'amount' => '10,00']]])
            ->assertSessionHasErrors('complete');
        $this->assertSame(AttendanceStatus::InProgress, $at->fresh()->status);

        $chave = $this->key();
        $envio = ['completion_key' => $chave, 'payments' => [['method' => 'pix', 'amount' => '115,00', 'tip' => '10,00']]];
        $j->from($voltar)->post(route('panel.attendances.complete', $at), $envio)->assertRedirect(route('panel.attendances.show', $at));
        $j->from($voltar)->post(route('panel.attendances.complete', $at), $envio)->assertRedirect(); // duplo clique
        $at->refresh();
        $this->assertSame([AttendanceStatus::Completed, 11500, 1000], [$at->status, $at->total_cents, $at->tip_cents]);
        $this->assertSame(1, Payment::query()->where('attendance_id', $at->id)->count());
        $this->assertGreaterThan(0, CommissionEntry::query()->where('attendance_id', $at->id)->count());
        $j->get($voltar)->assertOk()->assertSee('Total pago')->assertSee('R$ 10,00')->assertSee('Comprovante');
        $j->get(route('pro.today'))->assertOk()->assertSee('Você não tem atendimentos agora.');
    }

    // --- Anotacoes do cliente -------------------------------------------------------------------

    public function test_anotacoes_do_cliente_so_dos_proprios_clientes(): void
    {
        $ag = $this->todayAppointment();
        CustomerNote::query()->create(['customer_id' => $this->cliente->id, 'author_label' => 'Administração', 'visibility' => NoteVisibility::Team, 'body' => 'Nota interna da equipe']);
        CustomerNote::query()->create(['customer_id' => $this->cliente->id, 'author_label' => 'Barbeiros (sistema antigo)', 'visibility' => NoteVisibility::Professionals, 'body' => 'Prefere tesoura']);
        $j = $this->as($this->barbeiroJoao);

        $j->get(route('pro.appointments.show', $ag))->assertOk()->assertSee('Prefere tesoura')->assertDontSee('Nota interna da equipe');

        $j->from(route('pro.appointments.show', $ag))->post(route('pro.customers.notes.store', $this->cliente), ['note' => 'Disfarçado na zero'])->assertRedirect()->assertSessionHasNoErrors();
        $nota = CustomerNote::query()->where('body', 'Disfarçado na zero')->sole();
        $this->assertSame([NoteVisibility::Professionals, $this->barbeiroJoao->id, 'João'], [$nota->visibility, $nota->author_user_id, $nota->author_label]);
        $log = AuditLog::query()->where('action', 'customer.note_added')->sole();
        $this->assertStringNotContainsString('Disfarçado', (string) json_encode($log->new_values), 'a auditoria nao guarda o texto');

        $j->post(route('pro.customers.notes.store', $this->cliente), ['note' => str_repeat('a', CustomerNotes::MAX_LENGTH + 1)])->assertSessionHasErrors('note');
        $j->post(route('pro.customers.notes.store', $this->clienteDaMaria), ['note' => 'Tentativa'])->assertNotFound();

        // A Maria nao atende esse cliente: nem le, nem remove a anotacao do Joao.
        $this->as($this->barbeiraMaria)->delete(route('pro.customers.notes.destroy', [$this->cliente, $nota]))->assertNotFound();
        // Mesmo atendendo o cliente, so quem escreveu remove.
        $this->booking()->book(new BookingRequest(service: $this->corte, professional: $this->maria, start: $this->at($this->terca, '10:00'),
            channel: Channel::Staff, source: AppointmentSource::Staff, customer: $this->cliente));
        $this->as($this->barbeiraMaria)->delete(route('pro.customers.notes.destroy', [$this->cliente, $nota]))->assertNotFound();
        $this->assertNotNull($nota->fresh());

        $this->as($this->barbeiroJoao)->delete(route('pro.customers.notes.destroy', [$this->cliente, $nota]))->assertRedirect();
        $this->assertNull($nota->fresh());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.note_removed')->count());
    }

    // --- Tempo livre: mesma fotografia do dia da regra de agenda ------------------------------

    public function test_tempo_livre_segue_a_regra_da_agenda(): void
    {
        $this->book($this->terca, '14:00');
        $this->joao->breaks()->create(['weekday' => null, 'starts_at' => '12:00', 'ends_at' => '13:00', 'label' => 'Almoço', 'is_active' => true]);
        BlockedSlot::query()->create(['professional_id' => $this->joao->id, 'starts_at' => $this->at($this->terca, '16:00'), 'ends_at' => $this->at($this->terca, '17:00'), 'reason' => 'Curso']);

        $visao = $this->availability()->dayOverview($this->joao, $this->terca);
        $faixas = array_map(fn (Interval $i) => BusinessTime::local($i->start)->format('H:i').'-'.BusinessTime::local($i->end)->format('H:i'), $visao['free']);
        $this->assertSame(['09:00-12:00', '13:00-14:00', '14:30-16:00', '17:00-20:00'], $faixas);

        // Todo horario que a agenda oferece cabe num tempo livre, e todo tempo livre de 30 min ou mais oferece horario.
        $slots = $this->availability()->slots($this->corte, $this->joao, $this->terca, Channel::Staff);
        foreach ($slots as $s) {
            $this->assertTrue(collect($visao['free'])->contains(fn (Interval $f) => Interval::starting($s['start'], 30)->within($f)), 'horario fora do tempo livre');
        }
        foreach ($visao['free'] as $f) {
            $this->assertTrue(collect($slots)->contains(fn ($s) => $s['start']->gte($f->start) && $s['start']->lt($f->end)), 'tempo livre sem horario');
        }

        // Hoje, so a partir de agora; folga, nada livre.
        $this->travelTo($this->at($this->segunda, '10:07'));
        $hoje = $this->availability()->dayOverview($this->joao, $this->segunda, BusinessTime::now());
        $this->assertSame('10:07', BusinessTime::local($hoje['free'][0]->start)->format('H:i'));
        $this->joao->timeOff()->create(['starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'kind' => TimeOffKind::DayOff]);
        $folga = $this->availability()->dayOverview($this->joao, '2026-10-07');
        $this->assertTrue($folga['off']);
        $this->assertSame([], $folga['free']);
        $this->as($this->barbeiroJoao)->get(route('pro.agenda', ['data' => '2026-10-07']))->assertOk()->assertSee('Folga neste dia');
    }

    // --- Temas e dados ------------------------------------------------------------------------

    public function test_area_usa_o_tema_da_instalacao_nos_8_temas(): void
    {
        $this->startedAttendance();
        foreach (array_keys(Theme::THEMES) as $tema) {
            Appearance::chooseTheme($tema, $this->dono);
            foreach (['pro.today', 'pro.agenda', 'pro.attendances', 'pro.earnings', 'pro.profile'] as $rota) {
                $this->as($this->barbeiroJoao)->get(route($rota))->assertOk()->assertSee('data-tema="'.$tema.'"', false);
            }
        }
        $this->assertCount(8, Theme::THEMES);
    }

    public function test_css_da_area_so_usa_tokens_do_tema(): void
    {
        $css = (string) file_get_contents(resource_path('css/areas/professional.css'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $css, 'cor fixa (hex)');
        $this->assertDoesNotMatchRegularExpression('/\b(rgb|rgba|hsl|hsla|oklch)\(/i', $css, 'cor fixa (funcao)');
        $this->assertStringNotContainsString('[data-tema', $css, 'nenhuma regra so para um tema');
        $this->assertStringContainsString("@import './areas/professional.css'", (string) file_get_contents(resource_path('css/panel.css')));
    }

    public function test_abrir_as_telas_nao_altera_dados(): void
    {
        $at = $this->startedAttendance();
        $this->mariaToday();
        $tabelas = collect(Schema::getTableListing())
            ->map(fn (string $t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->reject(fn (string $t) => in_array($t, ['sessions', 'cache', 'cache_locks', 'jobs'], true))
            ->values();
        $retrato = fn () => $tabelas->mapWithKeys(fn (string $t) => [$t => md5((string) json_encode(DB::table($t)->get()))])->all();
        $antes = $retrato();

        foreach ([route('pro.today'), route('pro.agenda'), route('pro.agenda', ['data' => $this->terca]), route('pro.attendances'),
            route('pro.attendances.show', [$at, 'finalizar' => 1]), route('pro.appointments.show', $at->appointment),
            route('pro.earnings'), route('pro.earnings', ['mes' => '2026-09']), route('pro.profile')] as $url) {
            $this->as($this->barbeiroJoao)->get($url)->assertOk();
        }

        $this->assertSame($antes, $retrato());
    }
}

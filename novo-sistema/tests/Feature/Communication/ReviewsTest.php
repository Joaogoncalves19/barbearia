<?php

namespace Tests\Feature\Communication;

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRejected;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\ReviewRequests;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\System\Integrity\IntegrityChecker;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CommunicationFixtures;
use Tests\TestCase;

/**
 * Avaliacoes (avaliacoes.md; D-48, D-49): so atendimento concluido do
 * proprio cliente, uma por atendimento, publicada so depois da aprovacao,
 * moderacao auditada, comentario sempre texto (S-02) e pedido de avaliacao
 * uma vez por atendimento.
 */
class ReviewsTest extends TestCase
{
    use CommunicationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommunication();
    }

    private function reviews(): Reviews
    {
        return app(Reviews::class);
    }

    public function test_cliente_avalia_o_proprio_atendimento_concluido_e_fica_aguardando_revisao(): void
    {
        $at = $this->completedFor($this->cliente);

        $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.index'))->assertOk()->assertSee($at->code);
        $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.create', $at))->assertOk();
        $this->actingAs($this->cliente, 'customer')->post(route('account.reviews.store', $at), ['rating' => 5, 'comment' => "Ótimo!\nVoltarei."])
            ->assertRedirect(route('account.reviews.index'));

        $r = Review::query()->sole();
        $this->assertSame([ReviewStatus::Pending, 5, $at->id, $this->cliente->id, $this->joao->id, false], [$r->status, $r->rating, $r->attendance_id, $r->customer_id, $r->professional_id, $r->is_legacy]);
        $this->assertSame("Ótimo!\nVoltarei.", $r->comment);
        $this->assertSame(1, AuditLog::query()->where('action', 'review.submitted')->count());
        $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.index'))->assertSee('Em revisão');
    }

    public function test_nao_avalia_duas_vezes_o_mesmo_atendimento(): void
    {
        $at = $this->completedFor($this->cliente);
        $this->reviews()->submit($this->cliente, $at, 4, null);

        $this->actingAs($this->cliente, 'customer')->post(route('account.reviews.store', $at), ['rating' => 1])->assertSessionHas('status', 'Este atendimento já foi avaliado.');
        $this->expectException(ReviewRejected::class);
        $this->reviews()->submit($this->cliente, $at, 1, null);
    }

    public function test_ninguem_avalia_atendimento_de_outra_pessoa(): void
    {
        $at = $this->completedFor($this->cliente);
        $intruso = Customer::factory()->create();

        $this->actingAs($intruso, 'customer')->get(route('account.reviews.create', $at))->assertNotFound();
        $this->actingAs($intruso, 'customer')->post(route('account.reviews.store', $at), ['rating' => 1, 'comment' => 'ruim'])->assertNotFound();
        $this->assertSame(0, Review::query()->count());
        try {
            $this->reviews()->submit($intruso, $at, 1, null);
            $this->fail('serviço também recusa');
        } catch (ReviewRejected $e) {
            $this->assertSame('Este atendimento não é seu.', $e->getMessage());
        }
    }

    public function test_so_concluido_e_dentro_de_30_dias(): void
    {
        $aberto = $this->startedAttendance();
        $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.create', $aberto))->assertNotFound();

        $outro = Customer::factory()->create();
        $at = $this->completedFor($outro, '14:00');
        $this->travel(31)->days();
        $this->assertSame('O prazo para avaliar (30 dias) terminou.', $this->reviews()->ineligibilityReason($at));
        try {
            $this->reviews()->submit($outro, $at, 5, null);
            $this->fail('fora do prazo');
        } catch (ReviewRejected) {
        }
        $this->assertSame(0, Review::query()->count());
    }

    public function test_comentario_e_texto_nunca_html_em_toda_tela(): void
    {
        $at = $this->completedFor($this->cliente);
        $xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $r = $this->reviews()->submit($this->cliente, $at, 3, $xss."\x07");
        $this->assertSame($xss, $r->comment, 'guardado como veio (sem controle), não "limpo"');

        $dono = User::factory()->owner()->create();
        $this->reviews()->moderate($r, ReviewStatus::Approved, null, $dono);
        $this->reviews()->reply($r, '<b>obrigado</b>', $dono);

        foreach ([
            $this->actingAs($dono)->get(route('panel.reviews.index', ['situacao' => 'todas'])),
            $this->actingAs($this->cliente, 'customer')->get(route('account.reviews.index')),
        ] as $resp) {
            $resp->assertOk()->assertDontSee($xss, false)->assertDontSee('<b>obrigado</b>', false)
                ->assertSee('&lt;script&gt;', false)->assertSee('&lt;b&gt;obrigado&lt;/b&gt;', false);
        }
    }

    public function test_moderacao_auditada_recusa_com_motivo_destaque_e_resposta_so_publicada(): void
    {
        $at = $this->completedFor($this->cliente);
        $r = $this->reviews()->submit($this->cliente, $at, 2, 'Demorou');
        $gerente = User::factory()->manager()->create();

        $this->actingAs($gerente)->post(route('panel.reviews.feature', $r), ['featured' => 1])->assertSessionHasErrors('review');
        $this->actingAs($gerente)->post(route('panel.reviews.reply', $r), ['body' => 'Desculpe'])->assertSessionHasErrors('review');
        $this->actingAs($gerente)->post(route('panel.reviews.reject', $r), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($gerente)->post(route('panel.reviews.reject', $r), ['reason' => 'Ofensivo'])->assertSessionHas('status');
        $this->assertSame([ReviewStatus::Rejected, 'Ofensivo', $gerente->id], [$r->refresh()->status, $r->moderation_reason, $r->moderated_by_user_id]);

        $this->actingAs($gerente)->post(route('panel.reviews.approve', $r))->assertSessionHas('status');
        $this->actingAs($gerente)->post(route('panel.reviews.feature', $r), ['featured' => 1])->assertSessionHas('status');
        $this->actingAs($gerente)->post(route('panel.reviews.reply', $r), ['body' => 'Obrigado pelo retorno'])->assertSessionHas('status');
        $this->actingAs($gerente)->post(route('panel.reviews.reply', $r), ['body' => 'Obrigado!'])->assertSessionHas('status');

        $r->refresh();
        $this->assertSame([ReviewStatus::Approved, true, 'Obrigado!'], [$r->status, $r->is_featured, $r->reply?->body]);
        $this->assertSame(1, $r->replies()->count(), 'uma resposta por avaliação');
        $this->assertSame(['review.submitted', 'review.rejected', 'review.approved', 'review.featured', 'review.replied', 'review.replied'],
            AuditLog::query()->where('action', 'like', 'review.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame($gerente->id, AuditLog::query()->where('action', 'review.rejected')->sole()->actor_id);
        $this->assertSame([], app(IntegrityChecker::class)->violations());
    }

    public function test_permissoes_da_moderacao(): void
    {
        $at = $this->completedFor($this->cliente);
        $r = $this->reviews()->submit($this->cliente, $at, 5, 'Top');

        $this->actingAs($this->recepcao)->get(route('panel.reviews.index'))->assertOk()->assertSee('Top');
        $this->actingAs($this->recepcao)->post(route('panel.reviews.approve', $r))->assertForbidden();
        $this->actingAs($this->recepcao)->post(route('panel.reviews.reply', $r), ['body' => 'x'])->assertForbidden();
        $financeiro = User::factory()->role(StaffRole::Finance)->create();
        $this->actingAs($financeiro)->get(route('panel.reviews.index'))->assertForbidden();
        $this->assertSame(ReviewStatus::Pending, $r->refresh()->status);
    }

    public function test_profissional_ve_so_as_publicadas_dos_proprios_atendimentos(): void
    {
        $usuario = User::factory()->role(StaffRole::Professional)->create();
        $this->joao->forceFill(['user_id' => $usuario->id])->save();
        $at = $this->completedFor($this->cliente);
        $pendente = $this->reviews()->submit($this->cliente, $at, 5, 'Comentario pendente');
        $outro = Customer::factory()->create();
        $publicada = $this->reviews()->submit($outro, $this->completedFor($outro, '14:00'), 4, 'Comentario publicado');
        $this->reviews()->moderate($publicada, ReviewStatus::Approved, null, User::factory()->owner()->create());

        $this->actingAs($usuario)->get(route('panel.reviews.index', ['situacao' => 'pending']))->assertOk()
            ->assertSee('Comentario publicado')->assertDontSee('Comentario pendente')->assertDontSee((string) $outro->name);
        $this->actingAs($usuario)->post(route('panel.reviews.approve', $pendente))->assertForbidden();
    }

    public function test_pedido_de_avaliacao_uma_vez_por_atendimento_depois_de_3h(): void
    {
        $at = $this->completedFor($this->cliente);
        $this->assertSame(0, app(ReviewRequests::class)->run(), 'antes de 3 h, não pede');

        $this->travel(3)->hours();
        $this->assertSame(1, app(ReviewRequests::class)->run());
        $this->assertSame(0, app(ReviewRequests::class)->run(), 'rodar de novo não repete');
        $this->artisan('app:communication', ['task' => 'review-requests'])->assertSuccessful();

        $m = $this->emails('review_request')->sole();
        $this->assertSame([MessageStatus::Sent, 'review_request:'.$at->id], [$m->status, $m->dedupe_key]);
        $this->assertSame(1, CustomerNotification::query()->where('kind', 'review_request')->count());

        // Avaliou antes de o e-mail sair: o pedido nao se aplica mais (conferido na hora).
        $outro = Customer::factory()->create();
        $at2 = $this->completedFor($outro, '14:00');
        $this->travel(3)->hours();
        Queue::fake();
        app(ReviewRequests::class)->run();
        $this->reviews()->submit($outro, $at2, 5, null);
        $m2 = $this->emails('review_request')->last();
        $this->outbox()->deliver($m2->id);
        $this->assertSame(MessageStatus::Skipped, $m2->refresh()->status);
    }

    public function test_pedido_de_avaliacao_desligado_nao_envia(): void
    {
        CommunicationSettings::save(['review_request_enabled' => false], null);
        $this->completedFor($this->cliente);
        $this->travel(4)->hours();

        $this->assertSame(0, app(ReviewRequests::class)->run());
        $this->assertCount(0, $this->emails('review_request'));
    }
}

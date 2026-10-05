<?php

namespace Tests\Feature\Promotions;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Services\PaymentLine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Finance\Services\CommissionRules;
use App\Modules\Finance\Services\Payouts;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Mail\ReceiptMail;
use App\Modules\Receipts\Models\ReceiptDelivery;
use App\Modules\System\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\PromotionFixtures;
use Tests\TestCase;

/**
 * Comprovantes impressos e por e-mail (comprovantes.md; D-40 revista): os
 * quatro documentos, a mesma permissao da tela do documento, so documento
 * encerrado, envio em fila, registrado, auditado com o endereco mascarado,
 * idempotente, e o cliente so envia para o proprio e-mail.
 */
class ReceiptsTest extends TestCase
{
    use PromotionFixtures, RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotions();
        $this->dono = User::factory()->owner()->create();
        Mail::fake();
    }

    private function completed(): Attendance
    {
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($this->bookToday($this->cliente), $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, [new PaymentLine(PaymentMethod::Pix, 5000)], $this->key(), $this->recepcao);
    }

    public function test_os_quatro_comprovantes_imprimem_com_os_dados(): void
    {
        $at = $this->completed();
        app(CommissionRules::class)->set(CommissionTarget::Service, null, null, CommissionRuleType::Percent, 4000, null, null, $this->dono);
        $at2 = $this->attendances()->complete($this->attendances()->start($this->attendances()->openFromAppointment($this->bookToday($this->cliente, '11:00'), $this->recepcao), $this->recepcao), [new PaymentLine(PaymentMethod::Pix, 5000)], $this->key(), $this->recepcao);
        $repasse = app(Payouts::class)->pay($this->joao, PaymentMethod::Pix, null, $this->dono, $this->key());
        $vale = $this->giftCards()->sell(3000, PaymentMethod::Pix, ['recipient_name' => 'Presenteado Fictício', 'message' => 'Parabéns!'], $this->recepcao, 'v1');
        $caixa = $this->cash()->current();
        $this->assertNotNull($caixa);

        $this->actingAs($this->dono)->get(route('panel.receipts.attendance', $at))->assertOk()->assertSee($at->code)->assertSee('R$ 50,00')->assertSee('data-print', false);
        $this->actingAs($this->dono)->get(route('panel.receipts.payout', $repasse))->assertOk()->assertSee('R$ 20,00')->assertSee('receipt__sign', false)->assertSee('Líquido pago');
        $this->actingAs($this->dono)->get(route('panel.receipts.gift-card', $vale))->assertOk()->assertSee($vale->code)->assertSee('Presenteado Fictício')->assertSee('R$ 30,00');

        $this->actingAs($this->dono)->get(route('panel.receipts.cash', $caixa))->assertNotFound(); // caixa aberto ainda
        $this->cash()->close($caixa, $this->cash()->expectedCash($caixa), null, $this->dono);
        $this->actingAs($this->dono)->get(route('panel.receipts.cash', $caixa))->assertOk()->assertSee('Fechamento');
        $this->assertNotNull($at2);
    }

    public function test_so_atendimento_concluido_tem_comprovante(): void
    {
        $at = $this->attendances()->openFromAppointment($this->bookToday($this->cliente), $this->recepcao);

        $this->actingAs($this->dono)->get(route('panel.receipts.attendance', $at))->assertNotFound();
        $this->actingAs($this->dono)->post(route('panel.receipts.attendance.email', $at), ['request_key' => (string) Str::uuid(), 'email' => 'a@exemplo.test'])->assertNotFound();
    }

    public function test_permissao_e_a_mesma_da_tela_do_documento(): void
    {
        $at = $this->completed();
        app(CommissionRules::class)->set(CommissionTarget::Service, null, null, CommissionRuleType::Percent, 4000, null, null, $this->dono);
        $this->completedAt('11:00');
        $repasse = app(Payouts::class)->pay($this->joao, PaymentMethod::Pix, null, $this->dono, $this->key());
        $vale = $this->giftCards()->sell(3000, PaymentMethod::Pix, [], $this->recepcao, 'v1');
        $barbeiro = User::factory()->role(StaffRole::Professional)->create();

        $this->actingAs($this->recepcao)->get(route('panel.receipts.payout', $repasse))->assertNotFound(); // repasse alheio = 404 (Fase 7)
        $this->actingAs($barbeiro)->get(route('panel.receipts.gift-card', $vale))->assertForbidden();
        $this->actingAs($barbeiro)->post(route('panel.receipts.gift-card.email', $vale), ['request_key' => (string) Str::uuid(), 'email' => 'a@exemplo.test'])->assertForbidden();
        $this->actingAs($this->recepcao)->get(route('panel.receipts.attendance', $at))->assertOk();
        $this->actingAs($this->recepcao)->get(route('panel.receipts.gift-card', $vale))->assertOk();
        $this->get(route('panel.receipts.attendance', $at)); // sem login
        $this->assertSame(0, ReceiptDelivery::query()->count());
        Mail::assertNothingQueued();
    }

    private function completedAt(string $time): Attendance
    {
        $at = $this->attendances()->start($this->attendances()->openFromAppointment($this->bookToday($this->cliente, $time), $this->recepcao), $this->recepcao);

        return $this->attendances()->complete($at, [new PaymentLine(PaymentMethod::Pix, 5000)], $this->key(), $this->recepcao);
    }

    public function test_envio_por_email_em_fila_registrado_auditado_e_idempotente(): void
    {
        $at = $this->completed();
        $chave = (string) Str::uuid();

        $this->actingAs($this->recepcao)->from(route('panel.receipts.attendance', $at))
            ->post(route('panel.receipts.attendance.email', $at), ['request_key' => $chave, 'email' => ' Cliente.Ficticio@Exemplo.test '])
            ->assertRedirect(route('panel.receipts.attendance', $at))->assertSessionHas('status');
        $this->actingAs($this->recepcao)->post(route('panel.receipts.attendance.email', $at), ['request_key' => $chave, 'email' => 'cliente.ficticio@exemplo.test']);

        $envio = ReceiptDelivery::query()->sole();
        $this->assertSame([ReceiptType::Attendance, $at->id, 'cliente.ficticio@exemplo.test', $this->recepcao->id], [$envio->receipt_type, $envio->receipt_id, $envio->email, $envio->requested_by_user_id]);
        Mail::assertQueued(ReceiptMail::class, 1);
        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $m) => $m->hasTo('cliente.ficticio@exemplo.test') && $m->receiptId === $at->id);
        $log = AuditLog::query()->where('action', 'receipt.emailed')->sole();
        $this->assertStringNotContainsString('cliente.ficticio@', (string) json_encode($log->toArray()), 'auditoria guarda o endereço mascarado');

        $this->actingAs($this->recepcao)->post(route('panel.receipts.attendance.email', $at), ['request_key' => (string) Str::uuid(), 'email' => 'invalido'])->assertSessionHasErrors('email');
        $this->assertSame(1, ReceiptDelivery::query()->count());
    }

    public function test_email_renderiza_o_mesmo_comprovante(): void
    {
        $at = $this->completed();
        $html = (new ReceiptMail(ReceiptType::Attendance, $at->id, 'Comprovante'))->render();

        $this->assertStringContainsString($at->code, $html);
        $this->assertStringContainsString('R$ 50,00', $html);
        $this->assertStringNotContainsString('data-print', $html, 'sem botões no e-mail');
    }

    public function test_cliente_imprime_e_envia_so_para_o_proprio_email(): void
    {
        $this->cliente->forceFill(['email' => 'eu@exemplo.test'])->save();
        $at = $this->completed();

        $this->actingAs($this->cliente, 'customer')->get(route('account.attendances.print', $at))->assertOk()->assertSee($at->code)->assertSee('eu@exemplo.test');
        $this->actingAs($this->cliente, 'customer')->post(route('account.attendances.email', $at), ['request_key' => (string) Str::uuid(), 'email' => 'outro@exemplo.test'])->assertSessionHas('status');

        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $m) => $m->hasTo('eu@exemplo.test') && ! $m->hasTo('outro@exemplo.test'));
        $this->assertSame($this->cliente->id, ReceiptDelivery::query()->sole()->requested_by_customer_id);

        $outro = Customer::factory()->create(['email' => 'intruso@exemplo.test']);
        $this->flushSession(); // outra pessoa, outra sessao
        $this->actingAs($outro, 'customer')->get(route('account.attendances.print', $at))->assertNotFound();
        $this->actingAs($outro, 'customer')->post(route('account.attendances.email', $at), ['request_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(1, ReceiptDelivery::query()->count());
    }

    public function test_limite_de_envios(): void
    {
        $at = $this->completed();
        $status = [];
        for ($i = 0; $i < 12; $i++) {
            $status[] = $this->actingAs($this->recepcao)->post(route('panel.receipts.attendance.email', $at), ['request_key' => (string) Str::uuid(), 'email' => 'a@exemplo.test'])->status();
        }

        $this->assertSame(429, end($status));
        $this->assertSame(10, ReceiptDelivery::query()->count());
    }
}

<?php

namespace Tests\Feature\LegacyImport;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\LegacyImport\Testing\FictitiousLegacyDatabase;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportScenariosTest extends ImporterTestCase
{
    /** @var array<string, mixed> */
    private array $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSource();
        $this->r = $this->import();
        $this->assertSame('completed', $this->r['status'], $this->r['error'] ?? '');
    }

    private function customer(string $legacyId): object
    {
        return DB::table('customers')->where('id', $this->ref('clientes', $legacyId))->first();
    }

    private function appointment(string $code): ?object
    {
        return DB::table('appointments')->where('code', $code)->first();
    }

    public function test_duplicidades_nunca_sao_mescladas(): void
    {
        $original = $this->customer('CL-N00001');
        $dupEmail = $this->customer('CL-DUPMAIL');
        $this->assertSame('cliente1@exemplo.test', $original->email);
        $this->assertNull($dupEmail->email, 'o duplicado fica sem o e-mail em conflito');
        $this->assertNotNull($dupEmail->cpf, 'os demais dados do duplicado sao mantidos');

        $par = DB::table('customer_merge_candidates')->where('duplicate_customer_id', $dupEmail->id)->first();
        $this->assertSame($original->id, (int) $par->customer_id);
        $this->assertSame('email', $par->match_field);
        $this->assertSame('cliente1@exemplo.test', $par->match_value, 'valor original preservado para a decisao');
        $this->assertSame('pending', $par->status);

        $this->assertSame(['cpf', 'email', 'phone'], DB::table('customer_merge_candidates')->orderBy('match_field')->pluck('match_field')->all());
        $this->assertNotNull($this->customer('CL-DUPCOD'));
        $this->assertNull($this->customer('CL-DUPCOD')->referral_code);
        $this->assertCount(3, $this->issues($this->r, 'duplicate_customer'));
    }

    public function test_codigos_repetidos_de_cupom_e_vale(): void
    {
        $this->assertSame(['BEMVINDO', 'DESLIGADO', 'MENOS15'], DB::table('coupons')->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, (int) DB::table('coupons')->where('code', 'DESLIGADO')->value('is_active'), 'cupom desativado continua desativado');
        $this->assertSame(1000, DB::table('coupons')->where('code', 'BEMVINDO')->value('percent_bp'), 'o primeiro cadastrado e o importado');
        $this->assertNull(DB::table('coupons')->where('code', 'MENOS15')->value('max_uses'), 'usos_maximos 0 = ilimitado');
        $this->assertCount(1, $this->issues($this->r, 'duplicate_coupon_code', 'cup-2'));
        $this->assertCount(1, $this->issues($this->r, 'duplicate_voucher_code', 'vch-3'));
        $this->assertSame(1, DB::table('coupon_redemptions')->where('coupon_id', DB::table('coupons')->where('code', 'BEMVINDO')->value('id'))->whereNotNull('customer_id')->count());
    }

    public function test_orfaos(): void
    {
        $semBarbeiro = $this->appointment('AG-BARBORFAO');
        $placeholder = DB::table('professionals')->where('id', $semBarbeiro->professional_id)->first();
        $this->assertSame('Profissional removido (br-77)', $placeholder->display_name);
        $this->assertSame(0, (int) $placeholder->is_active);
        $this->assertSame($placeholder->id, DB::table('commission_payouts')->where('amount_cents', 30000)->value('professional_id'), 'mesmo ex-profissional nos agendamentos e nas comissoes');

        $semCliente = $this->appointment('AG-CLIORFAO');
        $this->assertNull($semCliente->customer_id);
        $this->assertSame('Visitante', $semCliente->customer_name);

        $this->assertNull($this->ref('clientes_assinaturas', 'CL-FANTASMA'), 'assinatura sem cliente nao entra...');
        $this->assertCount(1, $this->issues($this->r, 'orphan_subscription', 'CL-FANTASMA'), '...e vira pendencia com os ids do gateway');
        $this->assertSame('sub_FICTICIO_0099', $this->issues($this->r, 'orphan_subscription')[0]['context']['gateway_subscription_id']);

        $reviewOrfa = DB::table('reviews')->where('id', $this->ref('avaliacoes', 'av-5'))->first();
        $this->assertNull($reviewOrfa->appointment_id);
        $this->assertNotNull($reviewOrfa->professional_id);
    }

    public function test_ids_externos_preservados(): void
    {
        $sub = DB::table('subscriptions')->where('customer_id', $this->customer('CL-N00001')->id)->first();
        $this->assertSame('sub_FICTICIO_0001', $sub->gateway_subscription_id);
        $this->assertSame('cus_FICTICIO_0001', $sub->gateway_customer_id);
        $this->assertSame('stripe', $sub->gateway);
        $this->assertSame('active', $sub->status);

        $this->assertSame(['evt_FICTICIO_0001', 'evt_FICTICIO_0002'], DB::table('gateway_events')->orderBy('event_id')->pluck('event_id')->all());
        $this->assertSame('in_FICTICIO_0001', DB::table('subscription_payments')->where('id', $this->ref('assinatura_pagamentos', 'pag-1'))->value('gateway_payment_id'));
        $this->assertNull(DB::table('subscription_payments')->where('id', $this->ref('assinatura_pagamentos', 'pag-2'))->value('gateway_payment_id'), 'referencia repetida nao e copiada duas vezes');
        $this->assertSame('cs_FICTICIO_0002', $this->appointment('AG-PAGNOVO')->payment_gateway_reference);
        $this->assertSame('AG-N000001', $this->appointment('AG-N000001')->code, 'codigo antigo do agendamento preservado');
    }

    public function test_senhas_antigas_continuam_funcionando_e_sao_rehasheadas(): void
    {
        $cliente = Customer::find($this->customer('CL-N00001')->id);
        $hashAntigo = $cliente->getAuthPassword();
        $this->assertStringStartsWith('$2y$10$', $hashAntigo, 'hash antigo importado sem conversao');

        $this->assertTrue(Auth::guard('customer')->attempt(['email' => 'cliente1@exemplo.test', 'password' => FictitiousLegacyDatabase::LOGIN_PHRASE]));
        $novo = $cliente->fresh()->getAuthPassword();
        $this->assertNotSame($hashAntigo, $novo, 'rehash no primeiro login');
        $this->assertFalse(Hash::needsRehash($novo));
        $this->assertTrue(Hash::check(FictitiousLegacyDatabase::LOGIN_PHRASE, $novo));
        $this->assertFalse(Auth::guard('customer')->attempt(['email' => 'cliente1@exemplo.test', 'password' => 'errada']));

        // Equipe entra por usuario (D-10).
        $this->assertTrue(Auth::guard('web')->attempt(['username' => 'carlos', 'password' => FictitiousLegacyDatabase::LOGIN_PHRASE]));

        // Hash nao reconhecido: sem senha (nunca convertido nem adivinhado).
        $this->assertNull($this->customer('CL-SENHAMD5')->password);
        $this->assertNull($this->customer('CL-SEMSENHA')->password);
        $semSenha = User::where('name', 'Senha Antiga')->first();
        $this->assertNull($semSenha->password);
        $this->assertFalse($semSenha->is_active);
        $this->assertNull(User::where('name', 'antigo')->value('password'));
    }

    public function test_usuarios_repetidos_e_perfis_desconhecidos_ficam_sem_acesso(): void
    {
        $this->assertSame('dono', User::where('role', 'owner')->value('username'));
        $colisao = User::where('name', 'Dono')->first();
        $this->assertNull($colisao->username);
        $this->assertFalse($colisao->is_active);
        $barbeiroDono = User::where('name', 'Dono Barbeiro')->first();
        $this->assertNull($barbeiroDono->username, 'barbeiro nao herda o usuario do administrador');

        $estranho = User::where('name', 'estranho')->first();
        $this->assertSame('reception', $estranho->role->value);
        $this->assertFalse($estranho->is_active, 'perfil desconhecido: menor privilegio e inativo');
    }

    public function test_preferencias_de_marketing(): void
    {
        $this->assertSame('revoked', $this->customer('CL-OPTOUT')->marketing_email_consent, 'opt-out pelo id do cliente');
        $this->assertSame('revoked', $this->customer('CL-OPTMAIL')->marketing_email_consent, 'opt-out pelo e-mail');
        $this->assertSame(0, DB::table('customers')->where('marketing_email_consent', 'granted')->count(), 'ausencia de informacao nunca vira consentimento');
        $this->assertSame(DB::table('customers')->count() - 2, DB::table('customers')->where('marketing_email_consent', 'unknown')->count());

        $supressoes = DB::table('email_suppressions')->pluck('email')->all();
        foreach (['qualquer@exemplo.test', 'optout.email@exemplo.test', 'sem.cadastro@exemplo.test', 'invalido@@exemplo'] as $e) {
            $this->assertContains($e, $supressoes);
        }
        $this->assertSame(4, DB::table('consent_records')->where(['action' => 'revoked', 'source' => 'legacy_import'])->count());
    }

    public function test_dinheiro_em_centavos(): void
    {
        $this->assertSame(4500, DB::table('services')->where('id', $this->ref('servicos', 'sv-1'))->value('price_cents'));
        $this->assertSame(3000, DB::table('services')->where('id', $this->ref('servicos', 'sv-2'))->value('price_cents'));
        $this->assertCount(1, $this->issues($this->r, 'money_format_divergent', 'sv-2'), '"30,00" o sistema antigo lia de outro jeito: pendencia');
        $this->assertSame(3334, DB::table('services')->where('id', $this->ref('servicos', 'sv-5'))->value('price_cents'));
        $this->assertNull($this->ref('servicos', 'sv-4'), 'servico sem preco nao entra com preco inventado');
        $this->assertSame(9990, DB::table('subscription_payments')->where('id', $this->ref('assinatura_pagamentos', 'pag-1'))->value('amount_cents'), 'REAL 99.9 -> 9990 sem float');
        $this->assertSame(450, DB::table('appointment_adjustments')->where('appointment_id', $this->appointment('AG-DESCONTOBR')->id)->value('amount_cents'));

        $alto = $this->appointment('AG-DESCONTOALTO');
        $this->assertSame([7390, 4500, 2890], [(int) $alto->subtotal_cents, (int) $alto->discount_cents, (int) $alto->total_cents]);
        $this->assertSame(10000, DB::table('appointment_adjustments')->where('appointment_id', $alto->id)->value('amount_cents'), 'desconto original preservado');

        $cheque = DB::table('payments')->where('appointment_id', $this->appointment('AG-CHEQUE')->id)->first();
        $this->assertSame(['unknown', 250, 4500], [$cheque->method, (int) $cheque->tip_cents, (int) $cheque->amount_cents]);
        $this->assertSame('legacy_estimated', $cheque->amount_source);
    }

    public function test_item_sem_catalogo_fica_com_valor_desconhecido_e_sem_pagamento(): void
    {
        $ag = $this->appointment('AG-ITEMSUMIU');
        $itens = DB::table('appointment_items')->where('appointment_id', $ag->id)->orderBy('id')->get();
        $this->assertSame(['legacy_catalog_estimate', 'legacy_unknown'], $itens->pluck('price_source')->all());
        $this->assertNull($itens[1]->unit_price_cents);
        $this->assertNull($ag->total_cents);
        $this->assertSame(0, DB::table('payments')->where('appointment_id', $ag->id)->count());
        $this->assertCount(1, $this->issues($this->r, 'payment_amount_unknown', 'AG-ITEMSUMIU'));
    }

    public function test_status_e_regras_de_tempo(): void
    {
        $velho = $this->appointment('AG-PAGVELHO');
        $this->assertSame(['cancelled', 'system', 'payment_expired'], [$velho->status, $velho->cancelled_by, $velho->cancellation_reason]);
        $this->assertSame('awaiting_payment', $this->appointment('AG-PAGNOVO')->status);
        $this->assertSame('confirmed', $this->appointment('AG-PASSADOAPROV')->status);
        $this->assertCount(1, $this->issues($this->r, 'past_not_completed', 'AG-PASSADOAPROV'));
        $this->assertNull($this->appointment('AG-STATUSX'), 'status desconhecido nao e adivinhado');
        $this->assertNull($this->appointment('AG-DATARUIM'));
        $this->assertCount(1, $this->issues($this->r, 'future_overlap', 'AG-CONFLITO2'));

        $lembrete = $this->appointment('AG-LEMBRETE');
        $this->assertNotNull($lembrete->confirmed_at);
        $this->assertNotNull($lembrete->confirmation_requested_at);
        $this->assertSame(['day_before' => null, 'hours_before' => '2026-09-26 15:00:00'], DB::table('appointment_reminders')->where('appointment_id', $lembrete->id)->pluck('sent_at', 'kind')->all());

        // Horario local -> UTC, duracao pelos slots (combo = soma dos servicos).
        $conflito2 = $this->appointment('AG-CONFLITO2');
        $this->assertSame('2026-10-03 23:00:00', $conflito2->starts_at);
        $this->assertSame('2026-10-04 00:00:00', $conflito2->ends_at);
    }

    public function test_fidelidade_bate_com_o_saldo_antigo(): void
    {
        $l = app(LoyaltyLedger::class);
        $this->assertSame(5, $l->balance($this->customer('CL-N00001')->id));
        $this->assertSame(40, $l->balance($this->customer('CL-N00002')->id));
        $this->assertSame(0, $l->balance($this->customer('CL-N00003')->id), 'sem saldo no antigo = 0, historico mantido');
        $this->assertSame(-3, $l->balance($this->customer('CL-N00004')->id));
        $this->assertSame(30, (int) DB::table('loyalty_entries')->where(['customer_id' => $this->customer('CL-N00002')->id, 'kind' => 'legacy_opening'])->value('points'));
        $this->assertSame(10, (int) DB::table('stock_movements')->where('product_id', $this->ref('produtos', 'prod-1'))->sum('quantity'));
    }

    public function test_segredos_nunca_entram_no_banco_novo(): void
    {
        $tudo = json_encode(DB::table('settings')->get());
        $this->assertStringNotContainsString(FictitiousLegacyDatabase::FAKE_CREDENTIAL, $tudo);
        $this->assertSame(['legacy.config_agendamento', 'legacy.config_geral', 'legacy.fidelidade_config', 'legacy.landing_page'], DB::table('settings')->orderBy('key')->pluck('key')->all());
        $this->assertSame(['titulo' => 'Barbearia Exemplo', 'integracao' => ['cor' => '#000']], json_decode(DB::table('settings')->where('key', 'legacy.landing_page')->value('value'), true));
        $this->assertCount(3, $this->issues($this->r, 'secret_section_not_imported'));

        $relatorio = json_encode($this->r);
        $this->assertStringNotContainsString(FictitiousLegacyDatabase::FAKE_CREDENTIAL, $relatorio);
        $this->assertStringNotContainsString('$2y$', $relatorio, 'relatorio nao expoe hash de senha');
    }

    public function test_classificacao_dos_dados(): void
    {
        $classes = array_count_values(array_column($this->r['issues'], 'classification'));
        foreach (['potentially_valid', 'inconsistent', 'duplicate', 'orphan', 'legacy', 'unknown'] as $c) {
            $this->assertArrayHasKey($c, $classes, "Nenhum caso {$c} no banco ficticio");
        }
        $this->assertCount(1, $this->issues($this->r, 'unknown_table'));
        $this->assertSame('tabela_misteriosa', $this->issues($this->r, 'unknown_table')[0]['source_table']);
    }
}

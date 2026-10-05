<?php

namespace Tests\Feature\LegacyImport;

use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Services\ProfessionalLedger;
use App\Modules\Identity\Models\User;
use App\Modules\LegacyImport\Testing\FictitiousLegacyDatabase;
use App\Modules\Loyalty\Services\LoyaltyLedger;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Gateway\StripeSignature;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\System\Integrity\IntegrityChecker;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

    /**
     * Fase 9: a assinatura importada ganha a versao 1 do plano e um
     * identificador publico, e um evento do Stripe da MESMA assinatura (ID
     * preservado) e reconhecido depois da virada: a renovacao estende o
     * direito e o pagamento entra uma vez. Evento ja processado pelo sistema
     * antigo nao roda de novo.
     */
    public function test_assinatura_importada_e_reconhecida_pelos_eventos_do_stripe(): void
    {
        $sub = Subscription::query()->where('gateway_subscription_id', 'sub_FICTICIO_0001')->firstOrFail();
        $this->assertSame(['import', 1, 'Clube do Corte'], [$sub->origin->value, $sub->planVersion?->version, $sub->planName()]);
        $this->assertNotNull($sub->public_id);
        $this->assertSame(9990, $sub->planVersion?->price_cents);
        $this->assertSame('paid', DB::table('subscription_payments')->where('id', $this->ref('assinatura_pagamentos', 'pag-1'))->value('status'));

        $segredo = 'whsec_teste_'.Str::random(24);
        config(['services.stripe.webhook_secret' => $segredo]);
        $fim = $sub->ends_on?->copy()->addMonth();
        $evento = ['id' => 'evt_RENOVA_IMPORTADA', 'type' => 'invoice.paid', 'created' => now()->getTimestamp(), 'livemode' => false, 'data' => ['object' => [
            'id' => 'in_RENOVA_1', 'object' => 'invoice', 'subscription' => 'sub_FICTICIO_0001', 'customer' => 'cus_FICTICIO_0001', 'amount_paid' => 9990, 'currency' => 'brl',
            'billing_reason' => 'subscription_cycle', 'payment_intent' => 'pi_RENOVA_1', 'lines' => ['data' => [['period' => [
                'start' => BusinessTime::at((string) $sub->ends_on?->toDateString(), '10:00')->getTimestamp(),
                'end' => BusinessTime::at((string) $fim?->toDateString(), '10:00')->getTimestamp(),
            ]]]],
        ]]];
        $corpo = (string) json_encode($evento);
        $cab = StripeSignature::header($corpo, $segredo, now()->getTimestamp());
        foreach ([1, 2] as $_) {
            $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $cab, 'CONTENT_TYPE' => 'application/json'], $corpo)->assertOk();
        }

        $this->assertSame($fim?->toDateString(), $sub->refresh()->ends_on?->toDateString());
        $this->assertSame(1, DB::table('subscription_payments')->where('gateway_payment_id', 'in_RENOVA_1')->count());

        // Evento que o sistema antigo ja processou: reconhecido, sem efeito.
        $antigo = ['id' => 'evt_FICTICIO_0001', 'type' => 'invoice.paid', 'created' => now()->getTimestamp(), 'data' => ['object' => ['id' => 'in_X', 'object' => 'invoice', 'subscription' => 'sub_FICTICIO_0001', 'amount_paid' => 9990]]];
        $c2 = (string) json_encode($antigo);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => StripeSignature::header($c2, $segredo, now()->getTimestamp()), 'CONTENT_TYPE' => 'application/json'], $c2)
            ->assertOk()->assertJson(['result' => 'duplicate']);
        $this->assertSame(0, DB::table('subscription_payments')->where('gateway_payment_id', 'in_X')->count());
        $this->assertSame([], app(IntegrityChecker::class)->violations());
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

        $cheque = $this->paymentsOf($this->appointment('AG-CHEQUE')->id)->first();
        $this->assertSame(['unknown', 250, 4500], [$cheque->method, (int) $cheque->tip_cents, (int) $cheque->amount_cents]);
        $this->assertSame('legacy_estimated', $cheque->amount_source);

        // Concluido no sistema antigo = atendimento concluido (Fase 6), com os
        // mesmos valores e a gorjeta; o pagamento aponta o atendimento.
        $at = DB::table('attendances')->where('appointment_id', $this->appointment('AG-CHEQUE')->id)->first();
        $this->assertSame(['legacy', 'completed', 4500, 250], [$at->source, $at->status, (int) $at->total_cents, (int) $at->tip_cents]);
        $this->assertSame((int) $at->id, (int) $cheque->attendance_id);
        $this->assertSame(
            DB::table('appointment_items')->where('appointment_id', $at->appointment_id)->count(),
            DB::table('attendance_items')->where('attendance_id', $at->id)->count(),
        );
    }

    public function test_item_sem_catalogo_fica_com_valor_desconhecido_e_sem_pagamento(): void
    {
        $ag = $this->appointment('AG-ITEMSUMIU');
        $itens = DB::table('appointment_items')->where('appointment_id', $ag->id)->orderBy('id')->get();
        $this->assertSame(['legacy_catalog_estimate', 'legacy_unknown'], $itens->pluck('price_source')->all());
        $this->assertNull($itens[1]->unit_price_cents);
        $this->assertNull($ag->total_cents);
        $this->assertSame(0, $this->paymentsOf($ag->id)->count());
        $this->assertCount(1, $this->issues($this->r, 'payment_amount_unknown', 'AG-ITEMSUMIU'));
    }

    /**
     * Decisao da Fase 3 (D-17): agendamento antigo nunca concluido fica como
     * HISTORICO, mas nao vira atendimento, receita, comissao nem pontos.
     */
    public function test_agendamento_passado_nao_concluido_nao_gera_receita_comissao_nem_pontos(): void
    {
        $ag = $this->appointment('AG-PASSADOAPROV');

        $this->assertNotNull($ag, 'preservado como historico');
        $this->assertNotSame('completed', $ag->status);
        $this->assertNull($ag->completed_at);
        $this->assertSame(0, DB::table('attendances')->where('appointment_id', $ag->id)->count(), 'não vira atendimento');
        $this->assertSame(0, $this->paymentsOf($ag->id)->count(), 'sem receita');
        $this->assertSame(0, DB::table('commission_entries')->count(), 'sem comissao (o sistema antigo nao guardava; nada e recalculado)');
        $this->assertSame(0, DB::table('loyalty_entries')->where('appointment_id', $ag->id)->count(), 'sem pontos');
    }

    /**
     * Fase 7: o percentual do barbeiro vira a regra de comissao do
     * profissional (servicos); "comissao_produtos" = sim vira a regra de
     * produtos com o mesmo percentual. Vales antigos ficam como historico
     * (ja abatidos no sistema antigo) e nunca entram num repasse novo.
     * Fase 9: "comissao_assinatura_tipo" vira a regra de atendimento de
     * assinante ("padrao" = sem regra propria).
     */
    public function test_comissao_do_barbeiro_vira_regra_e_vales_antigos_sao_historico(): void
    {
        $carlos = $this->ref('barbeiros', 'br-1');
        $rafael = $this->ref('barbeiros', 'br-2');
        $regras = fn (?int $pro) => DB::table('commission_rules')->where('professional_id', $pro)->whereNotNull('current_scope')
            ->orderBy('target')->get(['target', 'type', 'rate_bp'])->map(fn ($r) => [$r->target, $r->type, (int) $r->rate_bp])->all();

        $this->assertSame([['product', 'percent', 4000], ['service', 'percent', 4000]], $regras($carlos));
        $this->assertSame([['service', 'percent', 3750], ['subscription', 'percent', 2000]], $regras($rafael), 'sem comissao de produto; assinante 20%');
        $this->assertSame([], $regras($this->ref('barbeiros', 'br-4')), 'percentual invalido: sem regra (pendencia registrada)');

        $this->assertSame(0, DB::table('advances')->where('is_legacy', false)->count(), 'todo vale importado e historico');
        $this->assertSame(0, app(ProfessionalLedger::class)->open((int) $carlos)['advances'], 'nao abate no repasse novo');
    }

    /** Fase 4: o importador gera o mesmo identificador estavel (slug) dos models. */
    public function test_catalogo_e_equipe_importados_ganham_slug_unico(): void
    {
        foreach (['service_categories', 'services', 'professionals'] as $tabela) {
            $slugs = DB::table($tabela)->pluck('slug');
            $this->assertNotContains(null, $slugs->all(), $tabela);
            $this->assertSame($slugs->count(), $slugs->unique()->count(), "{$tabela}: slug repetido");
        }
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
        $this->assertSame(['legacy.config_agendamento', 'legacy.config_geral', 'legacy.fidelidade_config', 'legacy.landing_page', 'promotions.policy'], DB::table('settings')->orderBy('key')->pluck('key')->all());
        // Fase 8: a fidelidade do sistema antigo (10 pontos por visita) vira a regra nova.
        $politica = json_decode((string) DB::table('settings')->where('key', 'promotions.policy')->value('value'), true);
        $this->assertSame(['visit', 10, true], [$politica['loyalty_earn_mode'], $politica['loyalty_points_per_visit'], $politica['loyalty_enabled']]);
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

    /** Pagamentos do atendimento nascido deste agendamento (Fase 6). */
    private function paymentsOf(int $appointmentId): Builder
    {
        return DB::table('payments')->whereIn('attendance_id', DB::table('attendances')->where('appointment_id', $appointmentId)->select('id'));
    }
}

<?php

namespace App\Modules\System\Integrity;

use App\Modules\System\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Verifica no BANCO as regras de integridade (regras-dados.md), inclusive
 * o que foi gravado sem passar pelos models (importador). Cada regra e uma
 * consulta que conta violacoes; zero em todas = integro.
 */
class IntegrityChecker
{
    /**
     * @return array<string, string> codigo => descricao
     */
    public function rules(): array
    {
        return array_map(fn ($r) => $r[0], $this->definitions());
    }

    /**
     * @return array<string, int> codigo => violacoes (so as regras violadas)
     */
    public function violations(): array
    {
        $achadas = [];
        foreach ($this->definitions() as $codigo => [, $contar]) {
            $n = (int) $contar();
            if ($n > 0) {
                $achadas[$codigo] = $n;
            }
        }

        return $achadas;
    }

    /**
     * @return array<string, array{0: string, 1: callable(): int}>
     */
    private function definitions(): array
    {
        $atuais = ['active', 'cancel_scheduled'];

        return [
            'R01_fk' => ['Chaves estrangeiras validas', fn () => DB::connection()->getDriverName() === 'sqlite' ? count(DB::select('PRAGMA foreign_key_check')) : 0],
            'R02_equipe_login' => ['Usuario ATIVO da equipe com e-mail ou usuario', fn () => DB::table('users')->where('is_active', true)->whereNull('email')->whereNull('username')->count()],
            'R03_cliente_email' => ['E-mail de cliente normalizado (minusculo, sem espacos)', fn () => DB::table('customers')->whereNotNull('email')->where(fn ($q) => $q->whereRaw('email <> LOWER(email)')->orWhere('email', 'like', '% %'))->count()],
            'R04_cliente_telefone' => ['Telefone de cliente em E.164 (+55...)', fn () => DB::table('customers')->whereNotNull('phone')->where(fn ($q) => $q->where('phone', 'not like', '+55%')->orWhereRaw('LENGTH(phone) NOT IN (13, 14)'))->count()],
            'R05_cliente_cpf' => ['CPF com 11 digitos', fn () => DB::table('customers')->whereNotNull('cpf')->whereRaw('LENGTH(cpf) <> 11')->count()],
            'R06_consentimento' => ['Consentimento de marketing com valor conhecido', fn () => DB::table('customers')->whereNotIn('marketing_email_consent', ['unknown', 'granted', 'revoked'])->count()],
            'R07_optout_coerente' => ['E-mail com opt-out nunca marcado como aceito', fn () => DB::table('customers')->join('email_suppressions', 'email_suppressions.email', '=', 'customers.email')->where('email_suppressions.reason', 'marketing_opt_out')->where('customers.marketing_email_consent', 'granted')->count()],
            'R08_agenda_intervalo' => ['Agendamento termina depois de comecar', fn () => DB::table('appointments')->whereColumn('ends_at', '<=', 'starts_at')->count()],
            'R09_item_preco' => ['Preco nulo somente em item antigo desconhecido', fn () => DB::table('appointment_items')->where(fn ($q) => $q->where(fn ($a) => $a->whereNull('unit_price_cents')->where('price_source', '<>', 'legacy_unknown'))->orWhere(fn ($b) => $b->whereNotNull('unit_price_cents')->where('price_source', 'legacy_unknown')))->count()],
            'R10_item_total' => ['Total do item = preco x quantidade', fn () => DB::table('appointment_items')->where(fn ($q) => $q->where('quantity', '<', 1)->orWhere('unit_price_cents', '<', 0)->orWhereRaw('COALESCE(total_cents, -1) <> COALESCE(unit_price_cents * quantity, -1)'))->count()],
            'R11_totais_agendamento' => ['Total = subtotal - desconto, desconto <= subtotal', fn () => DB::table('appointments')->whereNotNull('total_cents')->where(fn ($q) => $q->whereRaw('total_cents <> subtotal_cents - discount_cents')->orWhereColumn('discount_cents', '>', 'subtotal_cents')->orWhere('discount_cents', '<', 0))->count()],
            'R12_desconto_positivo' => ['Desconto nao negativo', fn () => DB::table('appointment_adjustments')->where('amount_cents', '<', 0)->count()],
            'R13_pagamento_valor' => ['Pagamento: valores nao negativos e total positivo', fn () => DB::table('payments')->where(fn ($q) => $q->where('amount_cents', '<', 0)->orWhere('tip_cents', '<', 0)->orWhereRaw('amount_cents + tip_cents <= 0'))->count()],
            'R14_estorno' => ['Estorno aponta o pagamento estornado', fn () => DB::table('payments')->where(fn ($q) => $q->where(fn ($a) => $a->where('kind', 'refund')->whereNull('refunds_payment_id'))->orWhere(fn ($b) => $b->where('kind', 'payment')->whereNotNull('refunds_payment_id')))->count()],
            'R15_avaliacao_nota' => ['Nota de avaliacao de 1 a 5', fn () => DB::table('reviews')->where(fn ($q) => $q->where('rating', '<', 1)->orWhere('rating', '>', 5))->count()],
            'R16_cupom' => ['Cupom coerente com o tipo de desconto', fn () => DB::table('coupons')->where(fn ($q) => $q->where(fn ($p) => $p->where('discount_type', 'percent')->where(fn ($x) => $x->whereNull('percent_bp')->orWhere('percent_bp', '<', 1)->orWhere('percent_bp', '>', 10000)->orWhereNotNull('amount_cents')))->orWhere(fn ($f) => $f->where('discount_type', 'fixed')->where(fn ($x) => $x->whereNull('amount_cents')->orWhere('amount_cents', '<=', 0)->orWhereNotNull('percent_bp')))->orWhereNotIn('discount_type', ['percent', 'fixed']))->count()],
            'R17_cupom_codigo' => ['Codigo de cupom em maiusculas', fn () => DB::table('coupons')->whereRaw('code <> UPPER(code)')->count()],
            'R18_comissao_percentual' => ['Comissao entre 0% e 100% (regras e assinatura)', fn () => DB::table('professionals')->where('subscription_commission_rate_bp', '>', 10000)->count()
                + DB::table('commission_rules')->where(fn ($q) => $q->where('rate_bp', '>', 10000)->orWhere('rate_bp', '<', 0)->orWhere('amount_cents', '<', 0))->count()],
            'R19_assinatura_vigente' => ['Sentinela de assinatura vigente coerente', fn () => DB::table('subscriptions')->where(fn ($q) => $q->where(fn ($a) => $a->whereIn('status', $atuais)->where(fn ($x) => $x->whereNull('active_customer_id')->orWhereColumn('active_customer_id', '<>', 'customer_id')))->orWhere(fn ($b) => $b->whereNotIn('status', $atuais)->whereNotNull('active_customer_id')))->count()],
            'R20_expediente' => ['Expediente valido (dia 0-6, fim > inicio)', fn () => DB::table('working_hours')->where(fn ($q) => $q->where('weekday', '>', 6)->orWhereColumn('ends_at', '<=', 'starts_at'))->count()],
            'R21_ausencia' => ['Ausencia termina depois de comecar', fn () => DB::table('time_off')->whereColumn('ends_on', '<', 'starts_on')->count()],
            'R22_servico' => ['Servico com duracao positiva e preco nao negativo', fn () => DB::table('services')->where(fn ($q) => $q->where('duration_minutes', '<=', 0)->orWhere('price_cents', '<', 0))->count()],
            'R23_segredo' => ['Nenhuma configuracao com segredo', fn () => DB::table('settings')->get(['key', 'value'])->filter(fn ($s) => Setting::secretPaths([$s->key => json_decode((string) $s->value, true)]) !== [])->count()],
            'R24_mesclagem' => ['Par de duplicidade com clientes distintos', fn () => DB::table('customer_merge_candidates')->whereColumn('customer_id', 'duplicate_customer_id')->count()],

            // Fase 6: atendimento, caixa e estoque.
            'R25_item_atendimento' => ['Item de atendimento: preco nulo so no legado; total = preco x quantidade', fn () => DB::table('attendance_items')->where(fn ($q) => $q->where('quantity', '<', 1)->orWhere('unit_price_cents', '<', 0)
                ->orWhere(fn ($a) => $a->whereNull('unit_price_cents')->where('price_source', '<>', 'legacy_unknown'))
                ->orWhere(fn ($b) => $b->whereNotNull('unit_price_cents')->where('price_source', 'legacy_unknown'))
                ->orWhereRaw('COALESCE(total_cents, -1) <> COALESCE(unit_price_cents * quantity, -1)'))->count()],
            'R26_atendimento_pago' => ['Atendimento concluido (novo) pago exatamente pelo total', fn () => DB::table('attendances')->where('status', 'completed')->where('source', '<>', 'legacy')
                ->whereRaw("COALESCE(total_cents, -1) <> (SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE payments.attendance_id = attendances.id AND payments.kind = 'payment')")->count()],
            'R27_atendimento_sentinela' => ['Um atendimento em vigor por agendamento (sentinela coerente)', fn () => DB::table('attendances')->where(fn ($q) => $q->where(fn ($a) => $a->where('status', '<>', 'cancelled')->whereNotNull('appointment_id')->where(fn ($x) => $x->whereNull('active_appointment_id')->orWhereColumn('active_appointment_id', '<>', 'appointment_id')))
                ->orWhere(fn ($b) => $b->where('status', 'cancelled')->whereNotNull('active_appointment_id')))->count()],
            'R28_desconto' => ['Desconto nao negativo e nunca maior que a base', fn () => DB::table('attendance_discounts')->where(fn ($q) => $q->where('amount_cents', '<', 0)->orWhereColumn('amount_cents', '>', 'base_cents'))->count()],
            'R29_caixa_aberto' => ['No maximo um caixa aberto', fn () => max(0, DB::table('cash_sessions')->where('status', 'open')->count() - 1)],
            'R30_caixa_movimento' => ['Movimento de caixa: entrada positiva, saida negativa; pagamento/estorno com origem', fn () => DB::table('cash_movements')->where(fn ($q) => $q->where('amount_cents', 0)
                ->orWhere(fn ($a) => $a->whereIn('type', ['payment', 'supply'])->where('amount_cents', '<', 0))
                ->orWhere(fn ($b) => $b->whereIn('type', ['refund', 'withdrawal'])->where('amount_cents', '>', 0))
                ->orWhere(fn ($c) => $c->whereIn('type', ['payment', 'refund'])->whereNull('payment_id')))->count()],
            'R31_pagamento_no_caixa' => ['Pagamento novo entrou no caixa (uma movimentacao)', fn () => DB::table('payments')->whereNotNull('cash_session_id')
                ->whereRaw('(SELECT COUNT(*) FROM cash_movements WHERE cash_movements.payment_id = payments.id) <> 1')->count()],
            'R32_estoque_origem' => ['Venda e consumo apontam o atendimento; estorno aponta o movimento', fn () => DB::table('stock_movements')->where(fn ($q) => $q->where(fn ($a) => $a->whereIn('kind', ['sale', 'consumption'])->whereNull('attendance_id'))
                ->orWhere(fn ($b) => $b->where('kind', 'reversal')->whereNull('reverses_movement_id'))
                ->orWhere(fn ($c) => $c->where('kind', '<>', 'reversal')->whereNotNull('reverses_movement_id')))->count()],
            'R33_encaixe_na_agenda' => ['Encaixe e agendamento de origem encaixe (ocupa a agenda), um para o outro', fn () => DB::table('attendances')->where('source', 'walk_in')
                ->where(fn ($q) => $q->whereNull('appointment_id')->orWhereNotExists(fn ($e) => $e->from('appointments')->whereColumn('appointments.id', 'attendances.appointment_id')->where('appointments.source', 'walk_in')))->count()
                + DB::table('appointments')->where('source', 'walk_in')->whereNotExists(fn ($e) => $e->from('attendances')->whereColumn('attendances.appointment_id', 'appointments.id')->where('attendances.source', 'walk_in'))->count()],
            // Fase 7: comissao, gorjeta, vales e repasse.
            'R34_comissao_por_item' => ['Atendimento concluido (novo) tem uma comissao calculada por item, do profissional que atendeu', fn () => DB::table('attendance_items')
                ->join('attendances', 'attendances.id', '=', 'attendance_items.attendance_id')
                ->where('attendances.status', 'completed')->where('attendances.source', '<>', 'legacy')->whereNotNull('attendances.professional_id')
                ->whereNotExists(fn ($e) => $e->from('commission_entries')->whereColumn('commission_entries.attendance_item_id', 'attendance_items.id')
                    ->whereColumn('commission_entries.professional_id', 'attendances.professional_id')->where('commission_entries.kind', 'earned'))->count()
                + DB::table('commission_entries')->where('kind', 'earned')->whereNotNull('attendance_item_id')
                    ->whereNotExists(fn ($e) => $e->from('attendance_items')->whereColumn('attendance_items.id', 'commission_entries.attendance_item_id')->whereColumn('attendance_items.attendance_id', 'commission_entries.attendance_id'))->count()],
            'R35_gorjeta_por_pagamento' => ['Toda gorjeta paga (atendimento novo) esta no razao de gorjeta, com o mesmo valor; estorno de gorjeta idem', fn () => DB::table('payments')
                ->join('attendances', 'attendances.id', '=', 'payments.attendance_id')
                ->where('attendances.source', '<>', 'legacy')->whereNotNull('attendances.professional_id')->where('payments.tip_cents', '>', 0)
                ->whereNotExists(fn ($e) => $e->from('tip_entries')->whereColumn('tip_entries.payment_id', 'payments.id')
                    ->whereRaw("tip_entries.amount_cents = CASE WHEN payments.kind = 'refund' THEN -payments.tip_cents ELSE payments.tip_cents END"))->count()],
            'R36_estorno_de_comissao' => ['Estorno de comissao nunca passa da comissao calculada do atendimento', fn () => DB::query()->fromSub(DB::table('commission_entries as r')
                ->where('r.kind', 'refund')->groupBy('r.attendance_id')->select('r.attendance_id')
                ->havingRaw('-SUM(r.amount_cents) > (SELECT COALESCE(SUM(e.amount_cents), 0) FROM commission_entries e WHERE e.attendance_id = r.attendance_id AND e.kind = ?)', ['earned']), 'excesso')
                ->count()],
            'R37_repasse_fecha' => ['Repasse: liquido = comissao + gorjeta - vales; lancamentos vinculados somam o mesmo; em dinheiro, saida igual no caixa', fn () => DB::table('commission_payouts')
                ->whereNotNull('snapshot')->whereRaw('amount_cents <> COALESCE(commission_cents, 0) + COALESCE(tip_cents, 0) - COALESCE(advances_cents, 0)')->count()
                + DB::table('commission_payouts')->whereNotNull('snapshot')->whereNull('reversed_at')
                    ->whereRaw('(COALESCE(commission_cents, 0) <> (SELECT COALESCE(SUM(amount_cents), 0) FROM commission_entries WHERE commission_entries.commission_payout_id = commission_payouts.id)
                        OR COALESCE(tip_cents, 0) <> (SELECT COALESCE(SUM(amount_cents), 0) FROM tip_entries WHERE tip_entries.commission_payout_id = commission_payouts.id)
                        OR COALESCE(advances_cents, 0) <> (SELECT COALESCE(SUM(amount_cents), 0) FROM advances WHERE advances.commission_payout_id = commission_payouts.id))')->count()
                + DB::table('commission_payouts')->whereNotNull('cash_session_id')
                    ->whereRaw("(SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE cash_movements.commission_payout_id = commission_payouts.id AND cash_movements.type = 'payout') <> -amount_cents")->count()
                + DB::table('commission_entries')->whereNotNull('commission_payout_id')->whereExists(fn ($e) => $e->from('commission_payouts')->whereColumn('commission_payouts.id', 'commission_entries.commission_payout_id')->whereNotNull('reversed_at'))->count()],
            'R38_vale' => ['Vale positivo; estorno de vale = valor inverso, uma vez, do mesmo profissional; vale do sistema antigo nunca entra em repasse novo', fn () => DB::table('advances as r')
                ->where('r.kind', 'reversal')->join('advances as o', 'o.id', '=', 'r.reverses_advance_id')
                ->where(fn ($q) => $q->whereColumn('r.professional_id', '<>', 'o.professional_id')->orWhereRaw('r.amount_cents <> -o.amount_cents')->orWhere('o.kind', '<>', 'advance'))->count()
                + DB::table('advances')->where(fn ($q) => $q->where(fn ($a) => $a->where('kind', 'advance')->where('amount_cents', '<=', 0))->orWhere(fn ($b) => $b->where('is_legacy', true)->whereNotNull('commission_payout_id')))->count()],
        ];
    }
}

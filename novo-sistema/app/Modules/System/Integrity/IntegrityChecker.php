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
            'R18_comissao_percentual' => ['Comissao entre 0% e 100%', fn () => DB::table('professionals')->where(fn ($q) => $q->where('commission_rate_bp', '>', 10000)->orWhere('subscription_commission_rate_bp', '>', 10000))->count()],
            'R19_assinatura_vigente' => ['Sentinela de assinatura vigente coerente', fn () => DB::table('subscriptions')->where(fn ($q) => $q->where(fn ($a) => $a->whereIn('status', $atuais)->where(fn ($x) => $x->whereNull('active_customer_id')->orWhereColumn('active_customer_id', '<>', 'customer_id')))->orWhere(fn ($b) => $b->whereNotIn('status', $atuais)->whereNotNull('active_customer_id')))->count()],
            'R20_expediente' => ['Expediente valido (dia 0-6, fim > inicio)', fn () => DB::table('working_hours')->where(fn ($q) => $q->where('weekday', '>', 6)->orWhereColumn('ends_at', '<=', 'starts_at'))->count()],
            'R21_ausencia' => ['Ausencia termina depois de comecar', fn () => DB::table('time_off')->whereColumn('ends_on', '<', 'starts_on')->count()],
            'R22_servico' => ['Servico com duracao positiva e preco nao negativo', fn () => DB::table('services')->where(fn ($q) => $q->where('duration_minutes', '<=', 0)->orWhere('price_cents', '<', 0))->count()],
            'R23_segredo' => ['Nenhuma configuracao com segredo', fn () => DB::table('settings')->get(['key', 'value'])->filter(fn ($s) => Setting::secretPaths([$s->key => json_decode((string) $s->value, true)]) !== [])->count()],
            'R24_mesclagem' => ['Par de duplicidade com clientes distintos', fn () => DB::table('customer_merge_candidates')->whereColumn('customer_id', 'duplicate_customer_id')->count()],
        ];
    }
}

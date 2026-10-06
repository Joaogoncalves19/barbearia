<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\Cpf;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Exportar meus dados" (LGPD, portabilidade e acesso; area-do-cliente.md).
 *
 * Um arquivo JSON com o que a barbearia guarda sobre o PROPRIO cliente:
 * cadastro, preferencias e prova de consentimento, agendamentos,
 * atendimentos e pagamentos dele, fidelidade, assinatura, avaliacoes, avisos
 * e o registro dos e-mails enviados (sem o corpo, que nao e guardado).
 *
 * Tudo parte do id do cliente logado. Fica de fora o que e da barbearia ou de
 * outras pessoas: custos, anotacoes internas da equipe, quem da equipe
 * recebeu o pagamento, dados das pessoas indicadas (so a quantidade) e
 * identificadores internos (vao os codigos que o cliente ve na tela). O CPF
 * sai mascarado, como em toda a interface (decisao P12-01 em aberto).
 */
final class CustomerDataExport
{
    /**
     * @return array<string, mixed>
     */
    public function build(Customer $customer): array
    {
        $id = $customer->id;

        $agendamentos = DB::table('appointments')->where('customer_id', $id)->orderBy('starts_at')->get();
        $itensAg = DB::table('appointment_items')->whereIn('appointment_id', $agendamentos->pluck('id'))->get()->groupBy('appointment_id');
        $descontosAg = DB::table('appointment_adjustments')->whereIn('appointment_id', $agendamentos->pluck('id'))->get()->groupBy('appointment_id');

        $atendimentos = DB::table('attendances')->where('customer_id', $id)->orderBy('opened_at')->get();
        $itensAt = DB::table('attendance_items')->whereIn('attendance_id', $atendimentos->pluck('id'))->get()->groupBy('attendance_id');
        $descontosAt = DB::table('attendance_discounts')->whereIn('attendance_id', $atendimentos->pluck('id'))->get()->groupBy('attendance_id');
        $pagamentos = DB::table('payments')->whereIn('attendance_id', $atendimentos->pluck('id'))->get()->groupBy('attendance_id');
        $codigoAtendimento = $atendimentos->pluck('code', 'id');
        $codigoAgendamento = $agendamentos->pluck('code', 'id');

        $assinaturas = DB::table('subscriptions')->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.customer_id', $id)->orderBy('subscriptions.id')
            ->get(['subscriptions.*', 'plans.name as plan_name']);
        $pagAssinatura = DB::table('subscription_payments')->where('customer_id', $id)->orderBy('paid_at')->get()->groupBy('subscription_id');
        $reembolsos = DB::table('subscription_refunds')->where('customer_id', $id)->get()->groupBy('subscription_payment_id');

        $avaliacoes = DB::table('reviews')->where('customer_id', $id)->orderBy('reviewed_at')->get();
        $respostas = DB::table('review_replies')->whereIn('review_id', $avaliacoes->pluck('id'))->get()->keyBy('review_id');

        $dados = [
            'gerado_em' => $this->date(BusinessTime::now()),
            'sobre' => 'Dados pessoais que '.config('app.name').' guarda sobre você (LGPD). Valores em centavos de real; datas no horário de Brasília.',
            'cadastro' => [
                'nome' => $customer->name,
                'email' => $customer->email,
                'email_confirmado_em' => $this->date($customer->email_verified_at),
                'celular' => $customer->phone,
                'cpf' => $customer->cpf !== null ? Cpf::mask($customer->cpf) : null,
                'data_de_nascimento' => $customer->birth_date?->format('Y-m-d'),
                'codigo_de_indicacao' => $customer->referral_code,
                'indicado_por_alguem' => $customer->referred_by_customer_id !== null,
                'pessoas_que_voce_indicou' => DB::table('customers')->where('referred_by_customer_id', $id)->count(),
                'cliente_desde' => $this->date($customer->created_at),
                'ultimo_acesso' => $this->date($customer->last_login_at),
            ],
            'preferencias' => [
                'novidades_e_promocoes_por_email' => $customer->marketing_email_consent->value,
                'lembretes_por_email' => (bool) $customer->email_reminders_enabled,
            ],
            'consentimentos' => DB::table('consent_records')->where('customer_id', $id)->orderBy('occurred_at')->get()
                ->map(fn ($r) => ['finalidade' => $r->purpose, 'acao' => $r->action, 'origem' => $r->source, 'quando' => $this->date($r->occurred_at)])->values()->all(),
            'profissionais_favoritos' => DB::table('customer_favorite_professionals')->join('professionals', 'professionals.id', '=', 'customer_favorite_professionals.professional_id')
                ->where('customer_id', $id)->pluck('professionals.display_name')->all(),
            'agendamentos' => $agendamentos->map(fn ($a) => [
                'codigo' => $a->code,
                'inicio' => $this->date($a->starts_at),
                'fim' => $this->date($a->ends_at),
                'situacao' => $a->status,
                'profissional' => $a->professional_name,
                'itens' => ($itensAg[$a->id] ?? collect())->map(fn ($i) => ['nome' => $i->name, 'quantidade' => (int) $i->quantity, 'valor_centavos' => $i->total_cents])->values()->all(),
                'descontos' => ($descontosAg[$a->id] ?? collect())->map(fn ($d) => ['tipo' => $d->kind, 'valor_centavos' => (int) $d->amount_cents])->values()->all(),
                'total_centavos' => $a->total_cents,
                'suas_observacoes' => $a->notes,
                'remarcacoes_pela_conta' => (int) $a->customer_reschedules,
                'cancelado_em' => $this->date($a->cancelled_at),
                'presenca_confirmada_em' => $this->date($a->presence_confirmed_at),
                'criado_em' => $this->date($a->created_at),
            ])->values()->all(),
            'atendimentos' => $atendimentos->map(fn ($t) => [
                'codigo' => $t->code,
                'agendamento' => $t->appointment_id !== null ? ($codigoAgendamento[$t->appointment_id] ?? null) : null,
                'situacao' => $t->status,
                'profissional' => $t->professional_name,
                'concluido_em' => $this->date($t->completed_at),
                'itens' => ($itensAt[$t->id] ?? collect())->map(fn ($i) => ['nome' => $i->name, 'quantidade' => (int) $i->quantity, 'valor_centavos' => $i->total_cents])->values()->all(),
                'descontos' => ($descontosAt[$t->id] ?? collect())->map(fn ($d) => ['tipo' => $d->kind, 'valor_centavos' => (int) $d->amount_cents])->values()->all(),
                'total_centavos' => $t->total_cents,
                'gorjeta_centavos' => $t->tip_cents,
                'pagamentos' => ($pagamentos[$t->id] ?? collect())->map(fn ($p) => ['tipo' => $p->kind, 'forma' => $p->method, 'valor_centavos' => (int) $p->amount_cents, 'quando' => $this->date($p->paid_at)])->values()->all(),
            ])->values()->all(),
            'fidelidade' => DB::table('loyalty_entries')->where('customer_id', $id)->orderBy('occurred_at')->get()
                ->map(fn ($e) => [
                    'pontos' => (int) $e->points, 'tipo' => $e->kind, 'descricao' => $e->description, 'quando' => $this->date($e->occurred_at),
                    'atendimento' => $e->attendance_id !== null ? ($codigoAtendimento[$e->attendance_id] ?? null) : null,
                ])->values()->all(),
            'assinaturas' => $assinaturas->map(fn ($s) => [
                'plano' => $s->plan_name,
                'situacao' => $s->status,
                'inicio' => $s->starts_on,
                'beneficio_ate' => $s->ends_on,
                'cancelada_em' => $this->date($s->cancelled_at),
                'pagamentos' => ($pagAssinatura[$s->id] ?? collect())->map(fn ($p) => [
                    'valor_centavos' => (int) $p->amount_cents, 'situacao' => $p->status, 'tipo' => $p->kind, 'pago_em' => $this->date($p->paid_at),
                    'periodo' => [$p->period_start, $p->period_end],
                    'reembolsos' => ($reembolsos[$p->id] ?? collect())->map(fn ($r) => ['valor_centavos' => (int) $r->amount_cents, 'situacao' => $r->status, 'quando' => $this->date($r->refunded_at)])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'avaliacoes' => $avaliacoes->map(fn ($r) => [
                'atendimento' => $r->attendance_id !== null ? ($codigoAtendimento[$r->attendance_id] ?? null) : null,
                'nota' => (int) $r->rating,
                'comentario' => $r->comment,
                'situacao' => $r->status,
                'quando' => $this->date($r->reviewed_at),
                'resposta_da_barbearia' => isset($respostas[$r->id]) ? $respostas[$r->id]->body : null,
            ])->values()->all(),
            'avisos' => DB::table('customer_notifications')->where('customer_id', $id)->orderBy('created_at')->get()
                ->map(fn ($n) => ['mensagem' => $n->message, 'quando' => $this->date($n->created_at), 'lido_em' => $this->date($n->read_at)])->values()->all(),
            'emails_enviados' => DB::table('email_messages')->where('customer_id', $id)->orderBy('created_at')->get()
                ->map(fn ($m) => ['tipo' => $m->category, 'modelo' => $m->template, 'para' => $m->to_email, 'assunto' => $m->subject, 'situacao' => $m->status, 'enviado_em' => $this->date($m->sent_at), 'criado_em' => $this->date($m->created_at)])->values()->all(),
        ];

        AuditTrail::record('customer.data_exported', $customer, $customer, 'Cliente exportou os próprios dados.', [
            'agendamentos' => count($dados['agendamentos']), 'atendimentos' => count($dados['atendimentos']),
        ]);

        return $dados;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instante = $value instanceof DateTimeInterface ? $value : CarbonImmutable::parse((string) $value, 'UTC');

        return BusinessTime::local($instante)->format('Y-m-d\TH:i:sP');
    }
}

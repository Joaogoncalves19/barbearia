<?php

namespace App\Modules\Customers\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Services\CommunicationRetention;
use App\Modules\Customers\Enums\ConsentAction;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Enums\MergeCandidateStatus;
use App\Modules\Customers\Models\ConsentRecord;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exclusao de conta do cliente = ANONIMIZACAO (R-33, LGPD; retencao-lgpd.md).
 *
 * Sai tudo o que identifica a pessoa; fica o que a barbearia precisa guardar
 * por obrigacao fiscal/contabil ou para a propria auditoria, sem o nome:
 *
 * - cadastro: nome vira "Cliente removido"; e-mail, celular, CPF, nascimento,
 *   foto, senha, codigo de indicacao e troca de e-mail pendente apagados;
 *   marketing revogado, lembretes desligados, conta inativa;
 * - agendamentos e atendimentos: ficam (agenda, caixa, comissao, relatorios),
 *   com o nome trocado e sem contato e observacoes;
 * - avaliacoes: ficam anonimas (R-33), com a nota e o comentario;
 * - e-mails, destinatarios de campanha e comprovantes enviados: endereco
 *   anonimizado como na retencao de 12 meses (P10-03); e-mails ainda na fila
 *   nao saem mais;
 * - avisos, anotacoes da equipe, favoritos, links de acesso, pedidos de nova
 *   senha e candidatos a mesclagem pendentes: apagados;
 * - corpo dos eventos do Stripe das assinaturas dele: apagado (como na
 *   retencao P9-10);
 * - trilha de auditoria: os registros continuam (quem fez o que e quando), sem
 *   os dados pessoais do cliente nos valores, no nome do autor e no IP;
 * - prova de consentimento: mantida (obrigacao legal) e acrescida da
 *   revogacao pela exclusao (o "registra opt-out" da R-33).
 *
 * Bloqueia enquanto houver algo em andamento que dependa do cadastro: horario
 * marcado, atendimento aberto ou assinatura vigente (inclusive aguardando
 * pagamento). Idempotente: conta ja anonimizada nao muda. Tudo numa transacao
 * com a linha do cliente travada; a prova do pedido fica em customer_erasures
 * (sem dado pessoal) e na auditoria.
 */
final class CustomerErasure
{
    public const ANONYMIZED_NAME = 'Cliente removido';

    /** Chaves com dado pessoal nos valores da auditoria: do cadastro e das copias dele no agendamento e no atendimento. */
    private const AUDIT_PERSONAL_KEYS = [
        'Customer' => ['name', 'email', 'phone', 'cpf', 'birth_date', 'photo_path', 'pending_email', 'remember_token'],
        'Appointment' => ['customer_name', 'customer_email', 'customer_phone', 'notes'],
        'Attendance' => ['customer_name', 'customer_phone', 'notes'],
    ];

    /**
     * O que impede a exclusao agora (mensagens para o cliente). Vazio = pode.
     *
     * @return list<string>
     */
    public function blockers(Customer $customer): array
    {
        $motivos = [];

        if (DB::table('appointments')->where('customer_id', $customer->id)->whereIn('status', ['pending', 'confirmed'])
            ->where('ends_at', '>', BusinessTime::now())->exists()) {
            $motivos[] = 'Você tem horário marcado. Cancele o horário (ou aguarde o atendimento) antes de excluir a conta.';
        }
        if (DB::table('attendances')->where('customer_id', $customer->id)
            ->whereIn('status', [AttendanceStatus::Open->value, AttendanceStatus::InProgress->value])->exists()) {
            $motivos[] = 'Há um atendimento seu em andamento na barbearia.';
        }
        $assinatura = DB::table('subscriptions')->where('customer_id', $customer->id)
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isCurrent())))
            ->value('status');
        if ($assinatura !== null) {
            $motivos[] = $assinatura === SubscriptionStatus::Pending->value
                ? 'Há uma assinatura aguardando pagamento. Fale com a barbearia para encerrá-la antes de excluir a conta.'
                : 'Sua assinatura ainda está vigente. Cancele a renovação e aguarde o fim do período pago (ou fale com a barbearia) antes de excluir a conta.';
        }

        return $motivos;
    }

    /**
     * Anonimiza. $actor: o proprio cliente (pela conta) ou alguem da equipe com
     * customers.anonymize (pedido feito na barbearia). Devolve as quantidades
     * tratadas (sem dado pessoal).
     *
     * @return array<string, int>
     *
     * @throws DomainRuleViolation se algo impede (ver blockers)
     */
    public function erase(Customer $customer, Customer|User $actor): array
    {
        return DB::transaction(function () use ($customer, $actor): array {
            /** @var Customer $c */
            $c = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($c->anonymized_at !== null) {
                return [];
            }
            if (($motivos = $this->blockers($c)) !== []) {
                throw DomainRuleViolation::rule('R-33', implode(' ', $motivos));
            }

            $agora = BusinessTime::now();
            $email = $c->email;
            $nomeAntigo = $c->name;
            $foto = $c->getAttribute('photo_path');
            $assinaturas = DB::table('subscriptions')->where('customer_id', $c->id)->pluck('id')->all();
            $redigido = CommunicationRetention::REDACTED;

            $n = [];
            $n['agendamentos'] = DB::table('appointments')->where('customer_id', $c->id)
                ->update(['customer_name' => self::ANONYMIZED_NAME, 'customer_email' => null, 'customer_phone' => null, 'notes' => null, 'updated_at' => $agora]);
            $n['atendimentos'] = DB::table('attendances')->where('customer_id', $c->id)
                ->update(['customer_name' => self::ANONYMIZED_NAME, 'customer_phone' => null, 'notes' => null, 'updated_at' => $agora]);
            $n['historico_agenda'] = DB::table('appointment_events')->where('actor_label', $nomeAntigo)
                ->whereIn('appointment_id', DB::table('appointments')->where('customer_id', $c->id)->select('id'))
                ->update(['actor_label' => 'Cliente']);
            $n['historico_atendimento'] = DB::table('attendance_events')->where('actor_label', $nomeAntigo)
                ->whereIn('attendance_id', DB::table('attendances')->where('customer_id', $c->id)->select('id'))
                ->update(['actor_label' => 'Cliente']);

            // E-mails: os da fila nao saem mais; todos ficam sem endereco/nome/assunto.
            DB::table('email_messages')->where('customer_id', $c->id)->where('status', MessageStatus::Queued->value)
                ->update(['status' => MessageStatus::Skipped->value, 'skip_reason' => 'Conta do cliente excluída.', 'updated_at' => $agora]);
            $n['emails'] = DB::table('email_messages')
                ->where(fn ($q) => $q->where('customer_id', $c->id)->when($email !== null, fn ($q) => $q->orWhere('to_email', $email)))
                ->where('to_email', '<>', $redigido)
                ->update(['to_email' => $redigido, 'to_name' => null, 'subject' => null, 'last_error' => null, 'purged_at' => $agora, 'updated_at' => $agora]);
            $n['destinatarios_campanha'] = DB::table('campaign_recipients')->where('customer_id', $c->id)->where('email', '<>', $redigido)
                ->update(['email' => $redigido, 'purged_at' => $agora, 'updated_at' => $agora]);
            $n['comprovantes_enviados'] = DB::table('receipt_deliveries')->where('requested_by_customer_id', $c->id)
                ->update(['email' => $redigido]);
            $n['eventos_stripe'] = $assinaturas === [] ? 0 : DB::table('gateway_events')->whereIn('subscription_id', $assinaturas)->whereNull('payload_purged_at')
                ->update(['payload' => null, 'payload_purged_at' => $agora]);

            $n['avisos'] = DB::table('customer_notifications')->where('customer_id', $c->id)->delete();
            $n['anotacoes_equipe'] = DB::table('customer_notes')->where('customer_id', $c->id)->delete();
            $n['favoritos'] = DB::table('customer_favorite_professionals')->where('customer_id', $c->id)->delete();
            $n['links_de_acesso'] = DB::table('customer_login_tokens')->where('customer_id', $c->id)->delete();
            $n['pedidos_de_senha'] = $email !== null ? DB::table('customer_password_reset_tokens')->where('email', $email)->delete() : 0;
            $n['mesclagens_pendentes'] = DB::table('customer_merge_candidates')->where('status', MergeCandidateStatus::Pending->value)
                ->where(fn ($q) => $q->where('customer_id', $c->id)->orWhere('duplicate_customer_id', $c->id))->delete();

            $n['auditoria'] = $this->scrubAudit($c->id, $nomeAntigo);

            // Prova da revogacao (consentimento.md): sem o endereco.
            if ($c->marketing_email_consent !== MarketingConsent::Revoked) {
                ConsentRecord::query()->create([
                    'customer_id' => $c->id, 'email' => null, 'purpose' => 'marketing_email', 'action' => ConsentAction::Revoked,
                    'source' => 'account_erasure', 'occurred_at' => $agora, 'evidence' => $actor instanceof User ? 'staff:'.$actor->id : 'customer',
                ]);
            }

            // Direto na tabela: o model gravaria na auditoria o nome e o e-mail antigos.
            DB::table('customers')->where('id', $c->id)->update([
                'name' => self::ANONYMIZED_NAME, 'email' => null, 'email_verified_at' => null, 'phone' => null, 'cpf' => null,
                'password' => null, 'remember_token' => null, 'birth_date' => null, 'photo_path' => null, 'referral_code' => null,
                'pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null,
                'marketing_email_consent' => MarketingConsent::Revoked->value, 'marketing_consent_updated_at' => $agora,
                'email_reminders_enabled' => false, 'status' => CustomerStatus::Inactive->value,
                'anonymized_at' => $agora, 'updated_at' => $agora,
            ]);

            DB::table('customer_erasures')->insert([
                'customer_id' => $c->id,
                'requested_by' => $actor instanceof User ? 'staff' : 'customer',
                'staff_user_id' => $actor instanceof User ? $actor->id : null,
                'counts' => json_encode($n, JSON_THROW_ON_ERROR),
                'erased_at' => $agora, 'created_at' => $agora, 'updated_at' => $agora,
            ]);

            // O arquivo sai depois da transacao (se ela desfizer, a foto continua).
            if (is_string($foto) && $foto !== '') {
                DB::afterCommit(fn () => Storage::disk('public')->delete($foto));
            }

            // Autor: a equipe, ou o proprio cadastro JA anonimizado (nunca o nome antigo).
            $anonimo = $c->fresh();
            AuditTrail::record('customer.anonymized', $anonimo, $actor instanceof User ? $actor : $anonimo,
                $actor instanceof User ? 'Conta de cliente anonimizada pela equipe (pedido LGPD).' : 'Cliente excluiu a própria conta (dados anonimizados).',
                $n);

            return $n;
        });
    }

    /**
     * Auditoria: os registros ficam, sem os dados pessoais do cliente. Valores
     * do proprio cadastro (nome, e-mail, celular, CPF mascarado, nascimento) e,
     * nas acoes feitas por ele, o nome do autor e o IP.
     */
    private function scrubAudit(int $customerId, string $oldName): int
    {
        $n = 0;
        $registros = [
            'Customer' => [$customerId],
            'Appointment' => DB::table('appointments')->where('customer_id', $customerId)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'Attendance' => DB::table('attendances')->where('customer_id', $customerId)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
        foreach ($registros as $tipo => $ids) {
            foreach (array_chunk($ids, 500) as $lote) {
                $n += $this->scrubAuditValues($tipo, $lote);
            }
        }

        $n += DB::table('audit_logs')->where('actor_type', 'Customer')->where('actor_id', $customerId)
            ->update(['actor_label' => 'Cliente', 'ip_address' => null]);
        // Acoes da equipe sobre o cliente que levam o nome na descricao.
        if ($oldName !== '') {
            $n += DB::table('audit_logs')->where('auditable_type', 'Customer')->where('auditable_id', $customerId)
                ->where('description', 'like', '%'.addcslashes($oldName, '%_\\').'%')
                ->update(['description' => null]);
        }

        return $n;
    }

    /**
     * @param  list<int>  $ids
     */
    private function scrubAuditValues(string $type, array $ids): int
    {
        $n = 0;
        $linhas = DB::table('audit_logs')->where('auditable_type', $type)->whereIn('auditable_id', $ids)
            ->get(['id', 'old_values', 'new_values']);
        foreach ($linhas as $l) {
            $mudou = [];
            foreach (['old_values', 'new_values'] as $col) {
                $v = is_string($l->{$col}) ? json_decode($l->{$col}, true) : null;
                if (! is_array($v)) {
                    continue;
                }
                $limpo = $v;
                foreach (self::AUDIT_PERSONAL_KEYS[$type] ?? [] as $k) {
                    if (array_key_exists($k, $limpo) && $limpo[$k] !== null) {
                        $limpo[$k] = CommunicationRetention::REDACTED;
                    }
                }
                if ($limpo !== $v) {
                    $mudou[$col] = json_encode($limpo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }
            if ($mudou !== []) {
                $n += DB::table('audit_logs')->where('id', $l->id)->update($mudou);
            }
        }

        return $n;
    }
}

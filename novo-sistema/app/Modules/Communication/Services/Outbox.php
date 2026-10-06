<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Delivery\EmailProviders;
use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Templates\RenderedEmail;
use App\Modules\Communication\Templates\TemplateRegistry;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Customers\Support\Email;
use App\Modules\Scheduling\Support\BusinessTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A fila de saida CENTRAL dos e-mails do dominio (emails.md, fila.md). Todo
 * e-mail do negocio passa por aqui: comprovantes, agendamento, lembretes,
 * assinatura, avaliacao e campanhas. Ninguem chama Mail:: por conta propria.
 *
 * - queue(): grava o pedido (com chave de unicidade: o mesmo e-mail do
 *   dominio nunca entra duas vezes) e dispara o job DEPOIS do commit da
 *   transacao do chamador. A requisicao HTTP nunca espera o provedor.
 * - deliver() (pelo job): "reivindica" o registro (so um processo envia),
 *   confere de novo consentimento/supressao, monta o e-mail com o estado
 *   ATUAL (pode desistir: "nao se aplica mais") e entrega. Erro: volta para
 *   a fila com o erro (o job tenta de novo com espera crescente); esgotou:
 *   "falhou" (pode ser reenviado com app:communication retry).
 * - Um job executado duas vezes nao reenvia: o registro ja enviado nao e
 *   reivindicado de novo.
 */
final class Outbox
{
    /** P10-01: campanhas de marketing por cliente na janela. */
    public const MARKETING_CAP = 4;

    public const MARKETING_CAP_DAYS = 30;

    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly EmailProviders $providers,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function queue(string $template, string $toEmail, ?string $toName, ?Customer $customer, array $params, ?string $dedupeKey, ?string $relatedType = null, ?int $relatedId = null, ?int $campaignId = null): ?EmailMessage
    {
        $email = Email::normalize($toEmail);
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        if ($dedupeKey !== null && ($existente = EmailMessage::query()->where('dedupe_key', $dedupeKey)->first()) !== null) {
            return $existente;
        }
        $modelo = $this->templates->get($template);

        try {
            $m = EmailMessage::query()->create([
                'category' => $modelo->category(),
                'template' => $template,
                'to_email' => $email,
                'to_name' => $toName !== null ? mb_substr($toName, 0, 255) : null,
                'customer_id' => $customer?->id,
                'params' => $params,
                'dedupe_key' => $dedupeKey,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'campaign_id' => $campaignId,
                'status' => MessageStatus::Queued,
                'queued_at' => BusinessTime::now(),
            ]);
        } catch (QueryException $e) {
            // Dois processos pedindo o mesmo e-mail ao mesmo tempo: o indice unico barra o segundo.
            if ($dedupeKey !== null && ($existente = EmailMessage::query()->where('dedupe_key', $dedupeKey)->first()) !== null) {
                return $existente;
            }
            throw $e;
        }
        SendEmailMessage::dispatch($m->id)->afterCommit();

        return $m;
    }

    /**
     * Entrega um registro (chamado pelo job). Devolve a situacao final ou
     * "queued" quando deve tentar de novo.
     *
     * @throws EmailDeliveryFailed erro de entrega, sem credenciais (o job tenta de novo)
     */
    public function deliver(int $messageId): MessageStatus
    {
        $pego = EmailMessage::query()->whereKey($messageId)->where('status', MessageStatus::Queued->value)
            ->update(['status' => MessageStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        $m = EmailMessage::query()->find($messageId);
        if ($pego === 0 || $m === null) {
            return $m->status ?? MessageStatus::Skipped; // ja enviado/decidido por outro processo
        }

        $bloqueio = $this->blockReason($m);
        if ($bloqueio !== null) {
            $m->forceFill(['status' => MessageStatus::Suppressed, 'skip_reason' => $bloqueio])->save();

            return MessageStatus::Suppressed;
        }

        $montado = $this->templates->get($m->template)->render($m);
        if (is_string($montado)) {
            $m->forceFill(['status' => MessageStatus::Skipped, 'skip_reason' => mb_substr($montado, 0, 255)])->save();

            return MessageStatus::Skipped;
        }

        // P10-01: no maximo 4 campanhas por cliente em 30 dias (so marketing).
        if ($m->category === MessageCategory::Marketing && ! $this->clearMarketingCap($m)) {
            $m->forceFill(['status' => MessageStatus::Suppressed, 'skip_reason' => 'Limite de '.self::MARKETING_CAP.' campanhas em '.self::MARKETING_CAP_DAYS.' dias.'])->save();

            return MessageStatus::Suppressed;
        }

        $provedor = $this->providers->current();
        try {
            $idProvedor = $provedor->send($m, $montado);
        } catch (Throwable $e) {
            $erro = $e instanceof EmailDeliveryFailed ? mb_substr($e->getMessage(), 0, 1000) : self::safeError($e);
            $m->forceFill(['status' => MessageStatus::Queued, 'last_error' => $erro, 'provider' => $provedor->name()])->save();
            // Para o worker sai so a mensagem limpa (sem a excecao original encadeada).
            throw new EmailDeliveryFailed($erro);
        }
        $m->forceFill([
            'status' => MessageStatus::Sent, 'sent_at' => BusinessTime::now(), 'subject' => mb_substr($montado->subject, 0, 255), 'last_error' => null,
            'provider' => $provedor->name(), 'provider_message_id' => $idProvedor,
        ])->save();

        return MessageStatus::Sent;
    }

    /**
     * P10-01 (decisao do dono): no maximo 4 campanhas de marketing por cliente
     * em qualquer janela de 30 dias. So contam e-mails de marketing ja
     * LIBERADOS por esta conferencia (e que nao falharam nem foram barrados).
     * A conferencia trava a linha do cliente: dois workers entregando campanhas
     * diferentes ao mesmo cliente ficam em fila e o limite nunca passa.
     */
    private function clearMarketingCap(EmailMessage $m): bool
    {
        if ($m->customer_id === null) {
            return false;
        }

        return DB::transaction(function () use ($m): bool {
            Customer::query()->whereKey($m->customer_id)->increment('communication_version');
            if (self::marketingCount($m->customer_id, $m->id) >= self::MARKETING_CAP) {
                return false;
            }
            EmailMessage::query()->whereKey($m->id)->update(['marketing_cleared_at' => BusinessTime::now(), 'updated_at' => now()]);

            return true;
        });
    }

    /** Campanhas liberadas para o cliente na janela de 30 dias (sem contar $except). */
    public static function marketingCount(int $customerId, ?int $except = null): int
    {
        return EmailMessage::query()->where('customer_id', $customerId)->where('category', MessageCategory::Marketing->value)
            ->whereNotNull('marketing_cleared_at')->where('marketing_cleared_at', '>=', BusinessTime::now()->subDays(self::MARKETING_CAP_DAYS))
            ->whereNotIn('status', [MessageStatus::Failed->value, MessageStatus::Suppressed->value, MessageStatus::Skipped->value])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->count();
    }

    /** Esgotou as tentativas (chamado pelo job). */
    public function markFailed(int $messageId, ?Throwable $e): void
    {
        EmailMessage::query()->whereKey($messageId)->whereNotIn('status', [MessageStatus::Sent->value, MessageStatus::Suppressed->value, MessageStatus::Skipped->value])
            ->update(['status' => MessageStatus::Failed->value, 'failed_at' => now(), 'last_error' => $e !== null ? self::safeError($e) : 'Falha sem detalhe (tentativas esgotadas).', 'updated_at' => now()]);
    }

    /** Reenvio manual de um registro que falhou (app:communication retry / painel). */
    public function retry(EmailMessage $m): bool
    {
        $ok = EmailMessage::query()->whereKey($m->id)->where('status', MessageStatus::Failed->value)
            ->update(['status' => MessageStatus::Queued->value, 'failed_at' => null, 'queued_at' => now(), 'updated_at' => now()]) === 1;
        if ($ok) {
            SendEmailMessage::dispatch($m->id);
        }

        return $ok;
    }

    /** Pre-visualizacao de um modelo com dados ficticios (painel). */
    public function preview(string $template): RenderedEmail
    {
        return $this->templates->get($template)->preview();
    }

    /**
     * Consentimento e supressao conferidos NA HORA do envio:
     * - marketing: consentimento CONCEDIDO e fora de qualquer supressao;
     * - transacional: so devolucao/reclamacao bloqueia (descadastro de
     *   marketing nao impede o e-mail necessario ao servico).
     */
    private function blockReason(EmailMessage $m): ?string
    {
        // Fase 12: conta excluida (anonimizada) ou registro ja anonimizado nao recebe nada.
        if ($m->to_email === CommunicationRetention::REDACTED
            || ($m->customer_id !== null && Customer::query()->whereKey($m->customer_id)->whereNotNull('anonymized_at')->exists())) {
            return 'Conta do cliente excluída (dados anonimizados).';
        }
        $supressao = EmailSuppression::query()->where('email', $m->to_email)->value('reason');
        if ($m->category === MessageCategory::Marketing) {
            $cliente = $m->customer_id !== null ? Customer::query()->find($m->customer_id) : null;
            if ($cliente === null || $cliente->marketing_email_consent !== MarketingConsent::Granted || $cliente->email !== $m->to_email) {
                return 'Sem consentimento de marketing.';
            }
            if ($supressao !== null) {
                return 'E-mail na lista de supressão ('.$supressao.').';
            }

            return null;
        }
        if (in_array($supressao, ['bounce', 'complaint'], true)) {
            return 'E-mail bloqueado por '.($supressao === 'bounce' ? 'devolução' : 'reclamação').'.';
        }

        return null;
    }

    /** Erro sem dados sensiveis: classe e mensagem curta, sem credenciais. */
    public static function safeError(Throwable $e): string
    {
        $msg = preg_replace('/(password|senha|token|secret|key)[^\s,;]*\s*[:=]\s*\S+/i', '$1=***', $e->getMessage()) ?? '';

        return mb_substr(class_basename($e).': '.$msg, 0, 1000);
    }
}

<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Exceptions\EmailDeliveryFailed;
use App\Modules\Communication\Jobs\SendEmailMessage;
use App\Modules\Communication\Mail\CommunicationMail;
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
use Illuminate\Support\Facades\Mail;
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
    public function __construct(private readonly TemplateRegistry $templates) {}

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

        try {
            Mail::to($m->to_email, $m->to_name)->send(new CommunicationMail($montado, $m->public_id));
        } catch (Throwable $e) {
            $erro = self::safeError($e);
            $m->forceFill(['status' => MessageStatus::Queued, 'last_error' => $erro])->save();
            // Para o worker sai so a mensagem limpa (sem a excecao original encadeada).
            throw new EmailDeliveryFailed($erro);
        }
        $m->forceFill(['status' => MessageStatus::Sent, 'sent_at' => BusinessTime::now(), 'subject' => mb_substr($montado->subject, 0, 255), 'last_error' => null])->save();

        return MessageStatus::Sent;
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

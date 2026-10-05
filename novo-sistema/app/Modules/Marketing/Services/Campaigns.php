<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Communication\Services\Outbox;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Enums\MarketingConsent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\EmailSuppression;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Exceptions\CampaignRejected;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Campanhas de e-mail (campanhas.md). Marketing, SEPARADO do transacional:
 * outra categoria na fila central, outra fila de envio (lote por minuto, no
 * ritmo configurado) e nunca bloqueia agendamento, pagamento, assinatura ou
 * atendimento.
 *
 * 1. Rascunho: nome, assunto, texto e publico (Segments).
 * 2. Envio de teste para um e-mail da equipe.
 * 3. Iniciar: o publico e FOTOGRAFADO (um destinatario por cliente, unico no
 *    banco); a contagem fica registrada.
 * 4. Rotina a cada minuto (app:communication campaigns): entrega N destinatarios a
 *    fila central (N = limite por minuto). Cada um e conferido de novo
 *    (consentimento e descadastro NA HORA); quem saiu e pulado.
 * 5. Cancelar: o que nao saiu nao sai mais.
 */
final class Campaigns
{
    public function __construct(
        private readonly Segments $segments,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, int|string|null>  $params
     *
     * @throws CampaignRejected
     */
    public function saveDraft(?Campaign $campaign, string $name, string $subject, string $body, string $segment, array $params, User $actor): Campaign
    {
        if ($campaign !== null && $campaign->status !== 'draft') {
            throw new CampaignRejected('Só rascunho pode ser editado.');
        }
        if (! array_key_exists($segment, Segments::ALL)) {
            throw new CampaignRejected('Público inválido.');
        }
        $dados = [
            'name' => mb_substr(trim($name), 0, 255), 'subject' => mb_substr(trim($subject), 0, 200), 'body' => mb_substr(trim($body), 0, 10000),
            'segment' => $segment, 'segment_params' => array_filter($params, fn ($v) => $v !== null && $v !== ''),
        ];
        if ($dados['name'] === '' || $dados['subject'] === '' || $dados['body'] === '') {
            throw new CampaignRejected('Informe nome, assunto e texto.');
        }
        $c = $campaign ?? new Campaign(['channel' => 'email', 'template' => 'campaign', 'status' => 'draft', 'created_by_user_id' => $actor->id, 'created_by_label' => $actor->name]);
        $c->fill($dados)->save();

        return $c;
    }

    public function audienceCount(Campaign $c): int
    {
        return $this->segments->query((string) $c->segment, $c->segment_params ?? [])->count();
    }

    public function sendTest(Campaign $c, string $email, User $actor): void
    {
        $this->outbox->queue('campaign_test', $email, $actor->name, null, ['campaign_id' => $c->id], null, 'campaign', $c->id);
        AuditTrail::record('campaign.test_sent', $c, $actor, 'Envio de teste da campanha.');
    }

    /**
     * @throws CampaignRejected
     */
    public function start(Campaign $campaign, User $actor, string $key): Campaign
    {
        $existente = Campaign::query()->where('request_key', $key)->first();
        if ($existente !== null) {
            return $existente;
        }

        return DB::transaction(function () use ($campaign, $actor, $key): Campaign {
            $c = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($c->status !== 'draft') {
                throw new CampaignRejected('A campanha já foi iniciada ou cancelada.');
            }
            $agora = BusinessTime::now();
            $total = 0;
            foreach ($this->segments->query((string) $c->segment, $c->segment_params ?? [])->select(['id', 'email'])->lazyById(500) as $cliente) {
                CampaignRecipient::query()->insertOrIgnore([
                    'campaign_id' => $c->id, 'customer_id' => $cliente->id, 'email' => (string) $cliente->email, 'status' => 'queued', 'created_at' => $agora, 'updated_at' => $agora,
                ]);
                $total++;
            }
            if ($total === 0) {
                throw new CampaignRejected('Nenhum cliente no público escolhido (só entra quem aceitou receber novidades).');
            }
            $c->forceFill(['status' => 'sending', 'total_recipients' => $total, 'started_at' => $agora, 'sent_by_user_id' => $actor->id, 'request_key' => $key])->save();
            AuditTrail::record('campaign.started', $c, $actor, 'Campanha iniciada para '.$total.' cliente(s).', ['publico' => $c->segment, 'total' => $total]);

            return $c;
        });
    }

    /**
     * Entrega o proximo lote de cada campanha em envio (rotina a cada minuto).
     *
     * @return array{entregues: int, pulados: int, concluidas: int}
     */
    public function processBatch(): array
    {
        $limite = CommunicationSettings::current()->int('campaign_per_minute');
        $n = ['entregues' => 0, 'pulados' => 0, 'concluidas' => 0];
        // Processo que caiu no meio do lote: o destinatario volta para a fila.
        // Sem risco de e-mail duplicado: a chave de unicidade da fila central
        // devolve o registro ja criado.
        CampaignRecipient::query()->where('status', 'dispatching')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'queued', 'updated_at' => now()]);
        foreach (Campaign::query()->where('status', 'sending')->orderBy('id')->get() as $c) {
            $lote = CampaignRecipient::query()->where('campaign_id', $c->id)->where('status', 'queued')->orderBy('id')->limit(max(0, $limite - $n['entregues'] - $n['pulados']))->get();
            foreach ($lote as $r) {
                // Reivindica o destinatario: dois processos nunca entregam o mesmo.
                if (CampaignRecipient::query()->whereKey($r->id)->where('status', 'queued')->update(['status' => 'dispatching', 'updated_at' => now()]) === 0) {
                    continue;
                }
                $cliente = $r->customer_id !== null ? Customer::query()->find($r->customer_id) : null;
                $motivo = $this->blockReason($cliente, $r->email);
                if ($motivo !== null) {
                    $r->forceFill(['status' => 'skipped', 'skip_reason' => $motivo])->save();
                    $n['pulados']++;

                    continue;
                }
                $m = $this->outbox->queue('campaign', $r->email, $cliente?->name, $cliente, ['campaign_id' => $c->id, 'recipient_id' => $r->id],
                    'campaign:'.$c->id.':'.$r->customer_id, 'campaign', $c->id, $c->id);
                $r->forceFill(['status' => 'dispatched', 'email_message_id' => $m?->id])->save();
                $n['entregues']++;
            }
            if (! CampaignRecipient::query()->where('campaign_id', $c->id)->whereIn('status', ['queued', 'dispatching'])->exists()) {
                $c->forceFill(['status' => 'completed', 'completed_at' => BusinessTime::now()])->save();
                $n['concluidas']++;
            }
            $this->refreshCounts($c);
        }

        return $n;
    }

    /**
     * @throws CampaignRejected
     */
    public function cancel(Campaign $campaign, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $c = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($c->status, ['draft', 'sending'], true)) {
                throw new CampaignRejected('A campanha já terminou.');
            }
            $pulados = CampaignRecipient::query()->where('campaign_id', $c->id)->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'Campanha cancelada', 'updated_at' => now()]);
            $c->forceFill(['status' => 'cancelled', 'cancelled_at' => BusinessTime::now()])->save();
            $this->refreshCounts($c);
            AuditTrail::record('campaign.cancelled', $c, $actor, 'Campanha cancelada.', ['nao_enviados' => $pulados]);

            return $c;
        });
    }

    /** Contagens a partir da fila central (o que de fato saiu). */
    public function refreshCounts(Campaign $c): void
    {
        $porSituacao = DB::table('email_messages')->where('campaign_id', $c->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $c->forceFill([
            'sent_count' => (int) ($porSituacao['sent'] ?? 0),
            'failed_count' => (int) ($porSituacao['failed'] ?? 0),
            'skipped_count' => CampaignRecipient::query()->where('campaign_id', $c->id)->where('status', 'skipped')->count() + (int) ($porSituacao['suppressed'] ?? 0) + (int) ($porSituacao['skipped'] ?? 0),
        ])->save();
    }

    private function blockReason(?Customer $customer, string $email): ?string
    {
        if ($customer === null || $customer->anonymized_at !== null || $customer->email !== $email) {
            return 'Cliente não existe mais ou mudou de e-mail.';
        }
        if ($customer->marketing_email_consent !== MarketingConsent::Granted) {
            return 'Sem consentimento de marketing.';
        }
        if (EmailSuppression::isSuppressed($email)) {
            return 'Descadastrado ou bloqueado.';
        }

        return null;
    }
}

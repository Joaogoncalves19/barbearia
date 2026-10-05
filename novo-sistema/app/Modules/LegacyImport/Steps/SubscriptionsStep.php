<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * planos, clientes_assinaturas, assinatura_pagamentos e
 * webhook_eventos_processados.
 *
 * IDs do Stripe (cliente, assinatura, pagamento, evento) sao copiados
 * exatamente como estao: a cobranca recorrente continua apos a virada e o
 * webhook novo reconhece eventos ja processados.
 *
 * Fase 9: o preco e os servicos do plano viram a VERSAO 1 do plano; cada
 * assinatura importada aponta essa versao, ganha um identificador publico e
 * a origem "importada". Pagamento "confirmado" vira "paid" (recebido);
 * eventos ja processados pelo sistema antigo ficam como "legacy".
 */
final class SubscriptionsStep extends Step
{
    public const STATUS_MAP = [
        'ativo' => 'active',
        'cancelamento_agendado' => 'cancel_scheduled',
        'expirado' => 'expired',
        'cancelado' => 'cancelled',
    ];

    public function name(): string
    {
        return 'Planos e assinaturas';
    }

    public function tables(): array
    {
        return ['planos', 'clientes_assinaturas', 'assinatura_pagamentos', 'webhook_eventos_processados'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('planos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('planos', $sid, $row) !== 'new') {
                continue;
            }
            $preco = $this->money('planos', $sid, 'valor', $row['valor'] ?? null);
            if ($preco === null || $preco < 0) {
                $this->ctx->skip('planos', $sid, C::Inconsistent, 'plan_without_price', 'Plano sem preco valido: nao importado.', ['nome' => $row['nome'] ?? null]);

                continue;
            }
            $id = $this->ctx->insert('plans', ['name' => $this->text('planos', $row['nome']) ?? '(sem nome)', 'is_active' => true, ...$this->stamps()]);
            $versao = $this->ctx->insert('plan_versions', [
                'plan_id' => $id, 'version' => 1, 'price_cents' => max(1, $preco), 'interval' => 'month', 'current_plan_id' => $id,
                'starts_at' => $this->ctx->now, 'reason' => 'Importado do sistema antigo', ...$this->stamps(),
            ]);
            if ($preco < 1) {
                $this->ctx->issue('planos', $sid, C::Inconsistent, S::Warning, 'plan_zero_price', 'Plano com preço zero no sistema antigo: importado com R$ 0,01 (revisar antes de novas adesões).', ['valor' => $row['valor'] ?? null], true);
            }
            foreach (array_unique(V::csv($row['servicos_ids'] ?? null)) as $servicoAntigo) {
                if ($servico = $this->ctx->ref('servicos', $servicoAntigo)) {
                    DB::table('plan_version_services')->insert(['plan_version_id' => $versao, 'service_id' => $servico]);
                } else {
                    $this->ctx->issue('planos', $sid, C::Orphan, S::Warning, 'plan_unknown_service', "Plano inclui servico inexistente {$servicoAntigo}.", ['servico_id' => $servicoAntigo], true);
                }
            }
            $this->ctx->remember('planos', $sid, 'plan', $id, $row);
        }

        foreach ($this->src->rows('clientes_assinaturas') as $row) {
            $sid = (string) $row['cliente_id'];
            if ($this->ctx->status('clientes_assinaturas', $sid, $row) !== 'new') {
                continue;
            }
            $gateway = [
                'gateway_customer_id' => V::text($row['gateway_customer_id'] ?? null),
                'gateway_subscription_id' => V::text($row['gateway_subscription_id'] ?? null),
            ];
            $cliente = $this->ctx->ref('clientes', $sid);
            if ($cliente === null) {
                $this->ctx->skip('clientes_assinaturas', $sid, C::Orphan, 'orphan_subscription',
                    'Assinatura de cliente inexistente: NAO importada. Verificar no Stripe antes da virada.', $gateway);

                continue;
            }
            $statusRaw = (string) V::text($row['status'] ?? null);
            $status = self::STATUS_MAP[$statusRaw] ?? null;
            if ($status === null) {
                $this->ctx->skip('clientes_assinaturas', $sid, C::Unknown, 'unknown_subscription_status', "Status de assinatura \"{$statusRaw}\" desconhecido.", $gateway);

                continue;
            }
            $plano = $this->ctx->ref('planos', $row['plano_id'] ?? null);
            if ($plano === null) {
                $this->ctx->issue('clientes_assinaturas', $sid, C::Orphan, S::Warning, 'subscription_unknown_plan', 'Assinatura de plano inexistente: importada sem plano.', ['plano_id' => $row['plano_id'] ?? null], true);
            }
            if ($gateway['gateway_subscription_id'] && DB::table('subscriptions')->where('gateway_subscription_id', $gateway['gateway_subscription_id'])->exists()) {
                $this->ctx->issue('clientes_assinaturas', $sid, C::Duplicate, S::Error, 'duplicate_gateway_subscription', 'Mesmo ID de assinatura do gateway em dois clientes: ID nao copiado neste.', $gateway, true);
                $gateway['gateway_subscription_id'] = null;
            }
            $fim = V::date($row['data_fim'] ?? null);
            if ($status === 'active' && $fim !== null && $fim < $this->ctx->now->setTimezone($this->ctx->timezone)->subDays(1)->toDateString()) {
                $this->ctx->issue('clientes_assinaturas', $sid, C::PotentiallyValid, S::Info, 'active_subscription_past_end',
                    'Assinatura ativa com fim no passado (fora da tolerancia): o sistema antigo expiraria na proxima verificacao.', ['data_fim' => $fim]);
            }
            $gatewayNome = V::text($row['gateway'] ?? null) ?? 'manual';
            $id = $this->ctx->insert('subscriptions', [
                'public_id' => (string) Str::uuid(),
                'customer_id' => $cliente,
                'plan_id' => $plano,
                'plan_version_id' => $plano !== null ? DB::table('plan_versions')->where('plan_id', $plano)->where('version', 1)->value('id') : null,
                'origin' => 'import',
                'status' => $status,
                'starts_on' => V::date($row['data_inicio'] ?? null),
                'ends_on' => $fim,
                'cancelled_at' => $this->local($row['cancelamento_em'] ?? null),
                'gateway' => in_array($gatewayNome, ['manual', 'stripe'], true) ? $gatewayNome : 'manual',
                'gateway_status' => V::text($row['gateway_status'] ?? null),
                'last_gateway_payment_id' => V::text($row['ultimo_pagamento_id'] ?? null),
                'active_customer_id' => in_array($status, ['active', 'cancel_scheduled'], true) ? $cliente : null,
                ...$gateway,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('clientes_assinaturas', $sid, 'subscription', $id, $row);
        }

        foreach ($this->src->rows('assinatura_pagamentos') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('assinatura_pagamentos', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
            $valor = $this->money('assinatura_pagamentos', $sid, 'valor', $row['valor'] ?? null);
            $referencia = V::text($row['referencia'] ?? null);
            if ($cliente === null || $valor === null) {
                $this->ctx->skip('assinatura_pagamentos', $sid, $cliente === null ? C::Orphan : C::Inconsistent, 'invalid_subscription_payment',
                    'Pagamento de assinatura sem cliente valido ou sem valor: NAO importado.', ['referencia' => $referencia, 'valor' => $row['valor'] ?? null]);

                continue;
            }
            if ($referencia !== null && DB::table('subscription_payments')->where('gateway_payment_id', $referencia)->exists()) {
                $this->ctx->issue('assinatura_pagamentos', $sid, C::Duplicate, S::Warning, 'duplicate_payment_reference', 'Referencia de pagamento repetida: gravada so no primeiro.', ['referencia' => $referencia], true);
                $referencia = null;
            }
            $assinatura = $this->ctx->ref('clientes_assinaturas', $row['cliente_id'] ?? null);
            $planoPg = $this->ctx->ref('planos', $row['plano_id'] ?? null);
            $statusPg = V::text($row['status'] ?? null) ?? 'confirmado';
            $id = $this->ctx->insert('subscription_payments', [
                'subscription_id' => $assinatura,
                'customer_id' => $cliente,
                'plan_id' => $planoPg,
                'plan_version_id' => $planoPg !== null ? DB::table('plan_versions')->where('plan_id', $planoPg)->where('version', 1)->value('id') : null,
                'gateway' => V::text($row['gateway'] ?? null) ?? 'manual',
                'gateway_payment_id' => $referencia,
                'gateway_subscription_id' => V::text($row['gateway_subscription_id'] ?? null),
                'amount_cents' => $valor,
                'currency' => strtoupper(V::text($row['moeda'] ?? null) ?? 'BRL'),
                'status' => $statusPg === 'confirmado' ? 'paid' : $statusPg,
                'kind' => V::text($row['tipo'] ?? null) ?? 'mensalidade',
                'paid_at' => $this->local($row['data_pagamento'] ?? null),
                'created_at' => $this->local($row['created_at'] ?? null) ?? $this->ctx->now,
            ]);
            $this->ctx->remember('assinatura_pagamentos', $sid, 'subscription_payment', $id, $row);
        }

        foreach ($this->src->rows('webhook_eventos_processados') as $row) {
            $sid = $row['gateway'].'|'.$row['evento_id'];
            if ($this->ctx->status('webhook_eventos_processados', $sid, $row) !== 'new') {
                continue;
            }
            $id = $this->ctx->insert('gateway_events', [
                'gateway' => (string) $row['gateway'],
                'event_id' => (string) $row['evento_id'],
                'type' => V::text($row['tipo'] ?? null),
                'processed_at' => $this->local($row['processado_em'] ?? null),
                'status' => 'processed',
                'result' => 'legacy',
            ]);
            $this->ctx->remember('webhook_eventos_processados', $sid, 'gateway_event', $id, $row);
        }
    }
}

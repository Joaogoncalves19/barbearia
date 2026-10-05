<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Support\LegacyValue as V;

/**
 * campanhas (so o resumo, D-20) e admin_atividade (auditoria antiga).
 */
final class MarketingAuditStep extends Step
{
    public function name(): string
    {
        return 'Campanhas e auditoria';
    }

    public function tables(): array
    {
        return ['campanhas', 'admin_atividade'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('campanhas') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('campanhas', $sid, $row) !== 'new') {
                continue;
            }
            $id = $this->ctx->insert('campaigns', [
                'channel' => 'email',
                'template' => mb_substr((string) V::text($row['template'] ?? null), 0, 64) ?: null,
                'subject' => $this->text('campanhas', $row['assunto'] ?? null),
                'segment' => mb_substr((string) V::text($row['segmento'] ?? null), 0, 64) ?: null,
                'status' => V::text($row['status'] ?? null) ?? 'desconhecido',
                'total_recipients' => max(0, V::int($row['total'] ?? null) ?? 0),
                'sent_count' => max(0, V::int($row['enviados'] ?? null) ?? 0),
                'failed_count' => max(0, V::int($row['falhas'] ?? null) ?? 0),
                'created_by_label' => V::text($row['criada_por'] ?? null),
                'started_at' => $this->local($row['criada_em'] ?? null),
                'completed_at' => $this->local($row['concluida_em'] ?? null),
                // Fase 10: so o resumo do sistema antigo (D-20); nunca reenviada.
                'is_legacy' => true,
                ...$this->stamps($this->local($row['criada_em'] ?? null)),
            ]);
            $this->ctx->remember('campanhas', $sid, 'campaign', $id, $row);
        }

        foreach ($this->src->rows('admin_atividade') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('admin_atividade', $sid, $row) !== 'new') {
                continue;
            }
            $acao = V::text($row['acao'] ?? null);
            if ($acao === null) {
                $this->ctx->skip('admin_atividade', $sid, C::Inconsistent, 'empty_activity', 'Registro de atividade sem acao.', [], false);

                continue;
            }
            $id = $this->ctx->insert('audit_logs', [
                'actor_type' => 'legacy_admin',
                'actor_label' => V::text($row['usuario'] ?? null),
                'action' => 'legacy.activity',
                'description' => trim($acao.' '.(string) $this->text('admin_atividade', $row['detalhes'] ?? null)),
                'created_at' => $this->local($row['created_at'] ?? null) ?? $this->ctx->now,
            ]);
            $this->ctx->remember('admin_atividade', $sid, 'audit_log', $id, $row);
        }
    }
}

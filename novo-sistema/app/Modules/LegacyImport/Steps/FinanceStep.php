<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * despesas, comissoes_pagas, vales, meta_financeira. Valores como estao
 * (comissoes pagas NAO sao recalculadas: o sistema antigo nao guardava a
 * comissao por atendimento).
 */
final class FinanceStep extends Step
{
    public function name(): string
    {
        return 'Financeiro';
    }

    public function tables(): array
    {
        return ['despesas', 'comissoes_pagas', 'vales', 'meta_financeira'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('despesas') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('despesas', $sid, $row) !== 'new') {
                continue;
            }
            $valor = $this->money('despesas', $sid, 'valor', $row['valor'] ?? null);
            $status = match (V::text($row['status'] ?? null)) {
                'pago' => 'paid', 'pendente', null => 'pending', default => null,
            };
            if ($valor === null || $status === null) {
                $this->ctx->skip('despesas', $sid, C::Inconsistent, 'invalid_expense', 'Despesa sem valor valido ou com status desconhecido.', $row);

                continue;
            }
            $id = $this->ctx->insert('expenses', [
                'description' => $this->text('despesas', $row['descricao'] ?? null) ?? '(sem descricao)',
                'category' => $this->text('despesas', $row['categoria'] ?? null),
                'amount_cents' => $valor,
                'due_on' => V::date($row['data_vencimento'] ?? null) ?? V::date($row['data_despesa'] ?? null),
                'paid_on' => V::date($row['data_pagamento'] ?? null),
                'status' => $status,
                'is_recurring' => V::bool($row['recorrente'] ?? null),
                ...$this->stamps(),
            ]);
            $this->ctx->remember('despesas', $sid, 'expense', $id, $row);
        }
        foreach ($this->src->rows('despesas') as $row) {
            $origem = V::text($row['recorrencia_origem'] ?? null);
            $id = $this->ctx->ref('despesas', (string) $row['id']);
            if ($origem !== null && $id !== null && ($pai = $this->ctx->ref('despesas', $origem)) && $pai !== $id) {
                DB::table('expenses')->where('id', $id)->whereNull('recurrence_parent_id')->update(['recurrence_parent_id' => $pai]);
            }
        }

        foreach ($this->src->rows('comissoes_pagas') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('comissoes_pagas', $sid, $row) !== 'new') {
                continue;
            }
            $valor = $this->money('comissoes_pagas', $sid, 'valor', $row['valor'] ?? null);
            $prof = $this->professionalFor($row['barbeiro_id'] ?? null, 'comissoes_pagas', $sid);
            if ($valor === null || $prof === null) {
                $this->ctx->skip('comissoes_pagas', $sid, C::Inconsistent, 'invalid_commission_payout', 'Comissao paga sem valor ou sem barbeiro.', $row);

                continue;
            }
            $mes = V::text($row['mes_ano'] ?? null);
            $id = $this->ctx->insert('commission_payouts', [
                'professional_id' => $prof,
                'amount_cents' => $valor,
                'tip_cents' => $this->money('comissoes_pagas', $sid, 'gorjeta', $row['gorjeta'] ?? null),
                'services_total_cents' => $this->money('comissoes_pagas', $sid, 'valor_total_servicos', $row['valor_total_servicos'] ?? null),
                'period_start' => V::date($row['periodo_inicio'] ?? null),
                'period_end' => V::date($row['periodo_fim'] ?? null),
                'reference_month' => ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) ? $mes : null,
                'paid_on' => V::date($row['data_pagamento'] ?? null),
                ...$this->stamps(),
            ]);
            $this->ctx->remember('comissoes_pagas', $sid, 'commission_payout', $id, $row);
        }

        foreach ($this->src->rows('vales') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('vales', $sid, $row) !== 'new') {
                continue;
            }
            $valor = $this->money('vales', $sid, 'valor', $row['valor'] ?? null);
            $prof = $this->professionalFor($row['barbeiro_id'] ?? null, 'vales', $sid);
            if ($valor === null || $prof === null) {
                $this->ctx->skip('vales', $sid, C::Inconsistent, 'invalid_advance', 'Vale sem valor ou sem barbeiro.', $row);

                continue;
            }
            $mes = V::text($row['mes_referencia'] ?? null);
            $id = $this->ctx->insert('advances', [
                'professional_id' => $prof, 'amount_cents' => $valor, 'issued_on' => V::date($row['data_vale'] ?? null),
                'reference_month' => ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) ? $mes : null,
                'description' => $this->text('vales', $row['descricao'] ?? null), ...$this->stamps(),
            ]);
            $this->ctx->remember('vales', $sid, 'advance', $id, $row);
        }

        foreach ($this->src->rows('meta_financeira') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('meta_financeira', $sid, $row) !== 'new') {
                continue;
            }
            $valor = $this->money('meta_financeira', $sid, 'valor', $row['valor'] ?? null);
            if ($valor === null || $valor <= 0) {
                $this->ctx->skip('meta_financeira', $sid, C::Inconsistent, 'invalid_goal', 'Meta financeira vazia ou invalida.', $row, false);

                continue;
            }
            $id = $this->ctx->insert('financial_goals', ['professional_id' => null, 'period' => 'monthly', 'amount_cents' => $valor, 'effective_from' => null, ...$this->stamps()]);
            $this->ctx->remember('meta_financeira', $sid, 'financial_goal', $id, $row);
        }
    }
}

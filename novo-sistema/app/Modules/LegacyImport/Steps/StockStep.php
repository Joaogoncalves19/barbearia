<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * estoque_logs -> stock_movements; produtos.quantidade vira UM movimento de
 * "ajuste de migracao" com a diferenca, para o saldo novo ser identico ao
 * antigo (o antigo corta em zero e aceita ajustes manuais sem registro).
 */
final class StockStep extends Step
{
    public function name(): string
    {
        return 'Estoque';
    }

    public function tables(): array
    {
        return ['estoque_logs'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('estoque_logs') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('estoque_logs', $sid, $row) !== 'new') {
                continue;
            }
            $produto = $this->ctx->ref('produtos', $row['produto_id'] ?? null);
            $qtd = V::int($row['quantidade'] ?? null);
            $tipo = V::text($row['tipo'] ?? null);
            if ($produto === null || $qtd === null || ! in_array($tipo, ['entrada', 'saida'], true)) {
                $this->ctx->skip('estoque_logs', $sid, $produto === null ? C::Orphan : C::Inconsistent, 'invalid_stock_log', 'Movimento de estoque sem produto valido, quantidade ou tipo conhecido.', $row, false);

                continue;
            }
            $motivo = $this->text('estoque_logs', $row['motivo'] ?? null);
            // Venda antiga ligada a um agendamento CONCLUIDO aponta o atendimento
            // dele (Fase 6); sem atendimento, fica como ajuste com o motivo original.
            $atendimento = null;
            if ($motivo && preg_match('/Agendamento\s+(\S+)/', $motivo, $m)) {
                $ag = $this->ctx->ref('agendamentos', $m[1]);
                $atendimento = $ag !== null ? DB::table('attendances')->where('appointment_id', $ag)->value('id') : null;
            }
            $id = $this->ctx->insert('stock_movements', [
                'product_id' => $produto,
                'quantity' => $tipo === 'entrada' ? abs($qtd) : -abs($qtd),
                'kind' => $tipo === 'entrada' ? 'purchase' : ($atendimento ? 'sale' : 'adjustment'),
                'reason' => $motivo, 'attendance_id' => $atendimento, 'actor_label' => V::text($row['usuario'] ?? null),
                'occurred_at' => $this->local($row['data_hora'] ?? null), 'created_at' => $this->ctx->now,
            ]);
            $this->ctx->remember('estoque_logs', $sid, 'stock_movement', $id, $row);
        }

        foreach ($this->src->rows('produtos') as $row) {
            $sid = (string) $row['id'];
            $produto = $this->ctx->ref('produtos', $sid);
            if ($produto === null || $this->ctx->ref('produtos:saldo', $sid) !== null) {
                continue;
            }
            $saldo = V::int($row['quantidade'] ?? null) ?? 0;
            if ($saldo < 0) {
                $this->ctx->issue('produtos', $sid, C::Inconsistent, S::Warning, 'negative_stock', 'Estoque negativo no sistema antigo: mantido.', ['quantidade' => $saldo], true);
            }
            $diferenca = $saldo - (int) DB::table('stock_movements')->where('product_id', $produto)->sum('quantity');
            $mov = $produto;
            if ($diferenca !== 0) {
                $mov = $this->ctx->insert('stock_movements', [
                    'product_id' => $produto, 'quantity' => $diferenca, 'kind' => 'legacy_opening',
                    'reason' => 'Ajuste de migracao: saldo do sistema antigo menos a soma das movimentacoes',
                    'occurred_at' => $this->ctx->now, 'created_at' => $this->ctx->now,
                ]);
                $this->ctx->count('produtos', 'opening_adjustments');
            }
            $this->ctx->remember('produtos:saldo', $sid, 'stock_opening', $mov, ['quantidade' => $row['quantidade'] ?? null]);
        }
    }
}

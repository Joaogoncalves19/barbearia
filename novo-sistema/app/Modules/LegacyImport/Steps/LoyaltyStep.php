<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Enums\IssueSeverity as S;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * fidelidade (saldo), fidelidade_historico (lancamentos) e cupom_usos.
 *
 * O saldo do sistema antigo e a verdade do cliente. O historico vira
 * lancamentos; a diferenca entre saldo e soma do historico vira UM
 * lancamento "Ajuste de migracao", para o saldo novo ser identico.
 */
final class LoyaltyStep extends Step
{
    public function name(): string
    {
        return 'Fidelidade e usos de cupom';
    }

    public function tables(): array
    {
        return ['fidelidade_historico', 'fidelidade', 'cupom_usos'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('fidelidade_historico') as $row) {
            $sid = $this->ctx->contentKey('fidelidade_historico', $row);
            if ($this->ctx->status('fidelidade_historico', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
            $pontos = V::int($row['pontos'] ?? null);
            if ($cliente === null || $pontos === null) {
                $this->ctx->skip('fidelidade_historico', $sid, $cliente === null ? C::Orphan : C::Inconsistent, 'invalid_loyalty_history', 'Lancamento de pontos sem cliente valido ou sem quantidade.', $row, false);

                continue;
            }
            if ($pontos === 0) {
                $this->ctx->count('fidelidade_historico', 'zero_points');
            }
            $id = $this->ctx->insert('loyalty_entries', [
                'customer_id' => $cliente, 'points' => $pontos, 'kind' => 'legacy_history',
                'description' => $this->text('fidelidade_historico', $row['descricao'] ?? null),
                'occurred_at' => $this->local($row['timestamp'] ?? null), 'created_at' => $this->ctx->now,
            ]);
            $this->ctx->remember('fidelidade_historico', $sid, 'loyalty_entry', $id, $row);
        }

        foreach ($this->src->rows('fidelidade') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('fidelidade', $sid, $row) !== 'new') {
                continue;
            }
            $cliente = $this->ctx->ref('clientes', $sid);
            $saldo = V::int($row['pontos'] ?? null);
            if ($cliente === null || $saldo === null) {
                $this->ctx->skip('fidelidade', $sid, $cliente === null ? C::Orphan : C::Inconsistent, 'invalid_loyalty_balance', 'Saldo de pontos de cliente inexistente ou ilegivel.', $row);

                continue;
            }
            if ($saldo < 0) {
                $this->ctx->issue('fidelidade', $sid, C::Inconsistent, S::Warning, 'negative_loyalty_balance', 'Saldo de pontos negativo no sistema antigo: mantido.', ['pontos' => $saldo], true);
            }
            $soma = (int) DB::table('loyalty_entries')->where('customer_id', $cliente)->sum('points');
            $diferenca = $saldo - $soma;
            $entryId = 0;
            if ($diferenca !== 0) {
                $entryId = $this->ctx->insert('loyalty_entries', [
                    'customer_id' => $cliente, 'points' => $diferenca, 'kind' => 'legacy_opening',
                    'description' => 'Ajuste de migracao: saldo do sistema antigo menos a soma do historico',
                    'occurred_at' => $this->ctx->now, 'created_at' => $this->ctx->now,
                ]);
                $this->ctx->count('fidelidade', 'opening_adjustments');
            }
            $this->ctx->remember('fidelidade', $sid, 'loyalty_balance', $entryId ?: $cliente, $row);
        }

        // Cliente com historico mas sem linha de saldo: o sistema antigo mostra 0.
        $comSaldo = [];
        foreach ($this->src->rows('fidelidade') as $row) {
            if ($c = $this->ctx->ref('clientes', (string) $row['id'])) {
                $comSaldo[$c] = true;
            }
        }
        $somas = DB::table('loyalty_entries')->where('kind', 'legacy_history')
            ->select('customer_id')->selectRaw('SUM(points) as total')->groupBy('customer_id')->get();
        foreach ($somas as $r) {
            $chave = (string) $r->customer_id;
            if (isset($comSaldo[(int) $r->customer_id]) || (int) $r->total === 0 || $this->ctx->ref('fidelidade:sem_saldo', $chave) !== null) {
                continue;
            }
            $entry = $this->ctx->insert('loyalty_entries', [
                'customer_id' => (int) $r->customer_id, 'points' => -((int) $r->total), 'kind' => 'legacy_opening',
                'description' => 'Ajuste de migracao: cliente sem saldo no sistema antigo (saldo exibido era 0)',
                'occurred_at' => $this->ctx->now, 'created_at' => $this->ctx->now,
            ]);
            $this->ctx->remember('fidelidade:sem_saldo', $chave, 'loyalty_entry', $entry, ['customer_id' => $chave, 'soma' => (int) $r->total]);
            $this->ctx->issue('fidelidade', null, C::Inconsistent, S::Warning, 'history_without_balance',
                'Cliente com historico de pontos e sem saldo no sistema antigo (que mostra 0): historico mantido e ajuste de migracao zera o saldo, como no sistema antigo.', ['customer_id' => (int) $r->customer_id, 'soma_historico' => (int) $r->total], true);
        }

        foreach ($this->src->rows('cupom_usos') as $row) {
            $sid = $this->ctx->contentKey('cupom_usos', $row);
            if ($this->ctx->status('cupom_usos', $sid, $row) !== 'new') {
                continue;
            }
            $cupom = $this->ctx->ref('cupoes', $row['cupom_id'] ?? null);
            $cliente = $this->ctx->ref('clientes', $row['cliente_id'] ?? null);
            if ($cupom === null) {
                $this->ctx->skip('cupom_usos', $sid, C::Orphan, 'orphan_coupon_use', 'Uso de cupom inexistente (ou de codigo repetido nao importado).', $row, false);

                continue;
            }
            if ($cliente !== null && DB::table('coupon_redemptions')->where(['coupon_id' => $cupom, 'customer_id' => $cliente])->exists()) {
                $this->ctx->skip('cupom_usos', $sid, C::Duplicate, 'duplicate_coupon_use', 'Mesmo cliente usou o cupom mais de uma vez (regra atual: 1 por cliente): mantido um.', $row, false);

                continue;
            }
            // Uso feito no sistema antigo (Fase 8: estado "usado" e a sentinela de 1 uso por cliente).
            $id = $this->ctx->insert('coupon_redemptions', ['coupon_id' => $cupom, 'customer_id' => $cliente, 'appointment_id' => null, 'redeemed_at' => null,
                'status' => 'redeemed', 'active_key' => $cliente !== null ? "c{$cupom}|u{$cliente}" : null, 'created_at' => $this->ctx->now]);
            $this->ctx->remember('cupom_usos', $sid, 'coupon_redemption', $id, $row);
        }
    }
}

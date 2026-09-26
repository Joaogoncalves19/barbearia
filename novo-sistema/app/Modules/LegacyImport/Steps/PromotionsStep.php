<?php

namespace App\Modules\LegacyImport\Steps;

use App\Modules\LegacyImport\Enums\IssueClassification as C;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Illuminate\Support\Facades\DB;

/**
 * cupoes e vouchers. Codigos passam a ser unicos: em codigo repetido o
 * primeiro cadastrado e importado e os demais viram pendencia (qual
 * manter e decisao do dono). Nada e renomeado automaticamente.
 */
final class PromotionsStep extends Step
{
    public function name(): string
    {
        return 'Cupons e vales-presente';
    }

    public function tables(): array
    {
        return ['cupoes', 'vouchers'];
    }

    protected function handle(): void
    {
        foreach ($this->src->rows('cupoes') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('cupoes', $sid, $row) !== 'new') {
                continue;
            }
            $codigo = mb_strtoupper((string) V::text($row['codigo'] ?? null));
            if ($codigo === '') {
                $this->ctx->skip('cupoes', $sid, C::Inconsistent, 'coupon_without_code', 'Cupom sem codigo.', $row);

                continue;
            }
            if (DB::table('coupons')->where('code', $codigo)->exists()) {
                $this->ctx->skip('cupoes', $sid, C::Duplicate, 'duplicate_coupon_code', "Codigo de cupom \"{$codigo}\" repetido: importado so o primeiro.", $row);

                continue;
            }
            $tipo = V::text($row['tipo_desconto'] ?? null) ?? 'percentual';
            $percent = null;
            $valor = null;
            if ($tipo === 'fixo') {
                $valor = $this->money('cupoes', $sid, 'valor_desconto', $row['valor_desconto'] ?? null);
                $ok = $valor !== null && $valor > 0;
            } else {
                $percent = V::percentBp($row['desconto_percentual'] ?? null);
                $ok = $tipo === 'percentual' && $percent !== null && $percent >= 1;
            }
            if (! $ok) {
                $this->ctx->skip('cupoes', $sid, C::Inconsistent, 'invalid_coupon_discount', 'Cupom com tipo ou valor de desconto invalido.', $row);

                continue;
            }
            $max = V::int($row['usos_maximos'] ?? null);
            $id = $this->ctx->insert('coupons', [
                'code' => $codigo,
                'discount_type' => $tipo === 'fixo' ? 'fixed' : 'percent',
                'percent_bp' => $percent,
                'amount_cents' => $valor,
                'max_uses' => ($max !== null && $max > 0) ? $max : null, // 0 = ilimitado no sistema atual
                'uses_count' => max(0, V::int($row['usos_atuais'] ?? null) ?? 0),
                'expires_on' => V::date($row['data_validade'] ?? null),
                'is_active' => V::text($row['ativo'] ?? null) === null || V::int($row['ativo']) !== 0,
                ...$this->stamps(),
            ]);
            $this->ctx->remember('cupoes', $sid, 'coupon', $id, $row);
        }

        foreach ($this->src->rows('vouchers') as $row) {
            $sid = (string) $row['id'];
            if ($this->ctx->status('vouchers', $sid, $row) !== 'new') {
                continue;
            }
            $codigo = V::text($row['codigo'] ?? null);
            $valor = $this->money('vouchers', $sid, 'valor', $row['valor'] ?? null);
            if ($codigo === null || $valor === null) {
                $this->ctx->skip('vouchers', $sid, C::Inconsistent, 'invalid_voucher', 'Vale-presente sem codigo ou valor.', $row);

                continue;
            }
            if (DB::table('gift_cards')->where('code', $codigo)->exists()) {
                $this->ctx->skip('vouchers', $sid, C::Duplicate, 'duplicate_voucher_code', "Codigo de vale \"{$codigo}\" repetido: importado so o primeiro.", $row);

                continue;
            }
            $statusRaw = V::text($row['status'] ?? null);
            $status = match ($statusRaw) {
                'disponivel', null => 'available',
                'utilizado' => 'redeemed',
                default => null,
            };
            if ($status === null) {
                $this->ctx->skip('vouchers', $sid, C::Unknown, 'unknown_voucher_status', "Status de vale \"{$statusRaw}\" desconhecido.", $row);

                continue;
            }
            $id = $this->ctx->insert('gift_cards', [
                'code' => $codigo,
                'amount_cents' => $valor,
                'status' => $status,
                'issued_at' => $this->local($row['data_criacao'] ?? null),
                'expires_on' => V::date($row['data_validade'] ?? null),
                'purchaser_name' => $this->text('vouchers', $row['comprador'] ?? null),
                ...$this->stamps(),
            ]);
            $this->ctx->remember('vouchers', $sid, 'gift_card', $id, $row);
        }
    }
}

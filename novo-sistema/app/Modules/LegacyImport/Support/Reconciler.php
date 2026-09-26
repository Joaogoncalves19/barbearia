<?php

namespace App\Modules\LegacyImport\Support;

use App\Modules\Customers\Support\Email;
use App\Modules\LegacyImport\Source\LegacyDatabase;
use App\Modules\LegacyImport\Support\LegacyValue as V;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Conciliacao origem x destino, calculada de forma INDEPENDENTE das etapas
 * (le a origem de novo e aplica a regra do sistema antigo). Qualquer
 * divergencia e bug do importador ou dado que exige decisao.
 */
final class Reconciler
{
    public function __construct(private readonly LegacyDatabase $src, private readonly string $tz) {}

    /**
     * @return array<string, array{ok: bool, detail: array<string, mixed>}>
     */
    public function run(): array
    {
        return [
            'saldo_fidelidade' => $this->loyalty(),
            'saldo_estoque' => $this->stock(),
            'opt_out_preservado' => $this->optOuts(),
            'assinaturas_gateway' => $this->subscriptions(),
            'faturamento_mensal' => $this->revenue(),
            'somas_financeiras' => $this->financialSums(),
        ];
    }

    private function ref(string $table, ?string $id): ?int
    {
        $v = $id === null ? null : DB::table('legacy_references')->where(['source_table' => $table, 'source_id' => $id])->value('entity_id');

        return $v === null ? null : (int) $v;
    }

    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function loyalty(): array
    {
        $erros = [];
        $n = 0;
        foreach ($this->src->rows('fidelidade') as $r) {
            $c = $this->ref('clientes', (string) $r['id']);
            $saldo = V::int($r['pontos'] ?? null);
            if ($c === null || $saldo === null) {
                continue;
            }
            $n++;
            $novo = (int) DB::table('loyalty_entries')->where('customer_id', $c)->sum('points');
            if ($novo !== $saldo) {
                $erros[] = ['cliente' => $r['id'], 'antigo' => $saldo, 'novo' => $novo];
            }
        }

        return ['ok' => $erros === [], 'detail' => ['clientes_conferidos' => $n, 'divergencias' => array_slice($erros, 0, 20)]];
    }

    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function stock(): array
    {
        $erros = [];
        $n = 0;
        foreach ($this->src->rows('produtos') as $r) {
            $p = $this->ref('produtos', (string) $r['id']);
            if ($p === null) {
                continue;
            }
            $n++;
            $antigo = V::int($r['quantidade'] ?? null) ?? 0;
            $novo = (int) DB::table('stock_movements')->where('product_id', $p)->sum('quantity');
            if ($novo !== $antigo) {
                $erros[] = ['produto' => $r['id'], 'antigo' => $antigo, 'novo' => $novo];
            }
        }

        return ['ok' => $erros === [], 'detail' => ['produtos_conferidos' => $n, 'divergencias' => $erros]];
    }

    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function optOuts(): array
    {
        $faltando = [];
        $n = 0;
        foreach ($this->src->rows('email_optout') as $r) {
            $email = Email::normalize($r['email'] ?? null) ?? mb_strtolower(trim((string) $r['email']));
            if ($email === '') {
                continue;
            }
            $n++;
            if (! DB::table('email_suppressions')->where('email', $email)->exists()) {
                $faltando[] = $email;
            }
        }

        return ['ok' => $faltando === [], 'detail' => ['opt_outs' => $n, 'faltando' => count($faltando)]];
    }

    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function subscriptions(): array
    {
        $erros = [];
        $vigentesAntigo = 0;
        foreach ($this->src->rows('clientes_assinaturas') as $r) {
            $sub = $this->ref('clientes_assinaturas', (string) $r['cliente_id']);
            if ($sub === null) {
                continue;
            }
            if (in_array($r['status'] ?? null, ['ativo', 'cancelamento_agendado'], true)) {
                $vigentesAntigo++;
            }
            $novo = DB::table('subscriptions')->where('id', $sub)->first();
            $gid = V::text($r['gateway_subscription_id'] ?? null);
            $cid = V::text($r['gateway_customer_id'] ?? null);
            $duplicado = $gid && DB::table('subscriptions')->where('gateway_subscription_id', $gid)->where('id', '<>', $sub)->exists();
            if ((! $duplicado && $novo->gateway_subscription_id !== $gid) || $novo->gateway_customer_id !== $cid || $novo->ends_on !== V::date($r['data_fim'] ?? null)) {
                $erros[] = ['cliente' => $r['cliente_id']];
            }
        }
        $vigentesNovo = DB::table('subscriptions')->whereNotNull('active_customer_id')->count();

        return ['ok' => $erros === [] && $vigentesAntigo === $vigentesNovo, 'detail' => ['vigentes_antigo' => $vigentesAntigo, 'vigentes_novo' => $vigentesNovo, 'divergencias' => $erros]];
    }

    /**
     * Regra do financeiro antigo: por atendimento concluido,
     * max(0, soma servicos/combos - desconto) + soma produtos, pelo mes da data.
     * So entram atendimentos cujos itens existem no catalogo (os demais tem
     * valor desconhecido nos dois lados e sao contados a parte).
     */
    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function revenue(): array
    {
        $precos = [];
        foreach (['servicos', 'combos'] as $t) {
            foreach ($this->src->rows($t) as $r) {
                $precos[$r['id']] = V::money($r['valor'] ?? null)['cents'];
            }
        }

        $antigo = [];
        $codigos = [];
        $desconhecidos = 0;
        foreach ($this->src->rows('agendamentos') as $a) {
            if (($a['status'] ?? null) !== 'concluido' || $this->ref('agendamentos', (string) $a['id']) === null) {
                continue;
            }
            $serv = 0;
            foreach (V::csv($a['servicos_ids'] ?? null) as $id) {
                if (! isset($precos[$id])) { // isset tambem e falso para preco nulo
                    $desconhecidos++;

                    continue 2;
                }
                $serv += $precos[$id];
            }
            $prod = 0;
            foreach ((array) json_decode((string) ($a['produtos_vendidos'] ?? ''), true) as $p) {
                $prod += (int) (V::money(isset($p['valor']) ? (string) $p['valor'] : null)['cents'] ?? 0);
            }
            $desc = max(0, (int) (V::money($a['desconto_aplicado'] ?? null)['cents'] ?? 0));
            $mes = substr((string) $a['data'], 0, 7);
            $antigo[$mes] = ($antigo[$mes] ?? 0) + max(0, $serv - $desc) + $prod;
            $codigos[] = (string) $a['id'];
        }

        $novo = [];
        foreach (array_chunk($codigos, 500) as $lote) {
            foreach (DB::table('appointments')->whereIn('code', $lote)->get(['starts_at', 'total_cents']) as $a) {
                $mes = CarbonImmutable::parse($a->starts_at, 'UTC')->setTimezone($this->tz)->format('Y-m');
                $novo[$mes] = ($novo[$mes] ?? 0) + (int) $a->total_cents;
            }
        }
        ksort($antigo);
        ksort($novo);
        $div = [];
        foreach (array_unique([...array_keys($antigo), ...array_keys($novo)]) as $m) {
            if (($antigo[$m] ?? 0) !== ($novo[$m] ?? 0)) {
                $div[$m] = ['antigo' => $antigo[$m] ?? 0, 'novo' => $novo[$m] ?? 0];
            }
        }

        return ['ok' => $div === [], 'detail' => ['meses' => count($antigo), 'total_centavos' => array_sum($antigo), 'atendimentos_com_item_desconhecido' => $desconhecidos, 'divergencias' => $div]];
    }

    /**
     * @return array{ok: bool, detail: array<string, mixed>}
     */
    private function financialSums(): array
    {
        $pares = [
            'despesas' => ['expenses', 'valor'],
            'comissoes_pagas' => ['commission_payouts', 'valor'],
            'vales' => ['advances', 'valor'],
            'assinatura_pagamentos' => ['subscription_payments', 'valor'],
        ];
        $res = [];
        $ok = true;
        foreach ($pares as $origem => [$destino, $campo]) {
            $antigo = 0;
            $ids = [];
            foreach ($this->src->rows($origem) as $r) {
                $id = $this->ref($origem, (string) $r['id']);
                if ($id !== null) {
                    $antigo += (int) V::money($r[$campo] ?? null)['cents'];
                    $ids[] = $id;
                }
            }
            $novo = 0;
            foreach (array_chunk($ids, 500) as $lote) {
                $novo += (int) DB::table($destino)->whereIn('id', $lote)->sum('amount_cents');
            }
            $res[$origem] = ['antigo' => $antigo, 'novo' => $novo];
            $ok = $ok && $antigo === $novo;
        }

        return ['ok' => $ok, 'detail' => $res];
    }
}

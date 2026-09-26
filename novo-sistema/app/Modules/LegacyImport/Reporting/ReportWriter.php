<?php

namespace App\Modules\LegacyImport\Reporting;

use App\Modules\LegacyImport\Enums\IssueClassification;
use Illuminate\Support\Facades\Storage;

/**
 * Relatorios da execucao, no disco privado (storage/app/private):
 * - <data>-<modo>.json   tudo (contadores, conciliacao, pendencias)
 * - <data>-<modo>.md     resumo legivel
 * - <data>-<modo>-pendencias.md  so o que precisa de decisao humana
 *
 * Nao contem senhas, hashes nem segredos (o contexto das pendencias omite
 * CPF e campos sensiveis na origem).
 */
class ReportWriter
{
    public const DIR = 'legacy-import/reports';

    /**
     * @param  array<string, mixed>  $r
     * @return array{json: string, markdown: string, pending: string}
     */
    public function write(array $r): array
    {
        $base = self::DIR.'/'.now('UTC')->format('Ymd-His').'-'.($r['mode'] === 'dry_run' ? 'simulacao' : 'importacao').($r['run_id'] ? '-'.$r['run_id'] : '');
        $r['classification'] = $this->classification($r);

        $disk = Storage::disk('local');
        $disk->put($base.'.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $disk->put($base.'.md', $this->summary($r));
        $disk->put($base.'-pendencias.md', $this->pending($r));

        return ['json' => $base.'.json', 'markdown' => $base.'.md', 'pending' => $base.'-pendencias.md'];
    }

    /**
     * Classificacao por tabela: valido = importado sem nenhuma pendencia.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, array<string, int>>
     */
    public function classification(array $r): array
    {
        $comPendencia = [];
        $porClasse = [];
        foreach ($r['issues'] as $i) {
            $porClasse[$i['source_table']][$i['classification']] = ($porClasse[$i['source_table']][$i['classification']] ?? 0) + 1;
            if ($i['source_id'] !== null) {
                $comPendencia[$i['source_table']][$i['source_id']] = true;
            }
        }
        $out = [];
        foreach ($r['counters'] as $tabela => $c) {
            $importados = $c['imported'] ?? 0;
            $out[$tabela] = ['valid' => max(0, $importados - count($comPendencia[$tabela] ?? []))] + ($porClasse[$tabela] ?? []);
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function summary(array $r): string
    {
        $modo = $r['mode'] === 'dry_run' ? 'Simulação (dry-run): NADA foi gravado' : 'Importação';
        $md = "# Relatório do importador — {$modo}\n\n";
        $md .= "| Item | Valor |\n|---|---|\n";
        $md .= '| Situação | **'.$r['status']."** |\n";
        $md .= '| Origem | `'.basename($r['source'])."` |\n";
        $md .= '| SHA-256 da origem (antes = depois) | `'.substr($r['source_sha256'], 0, 16).'…` '.($r['source_unchanged'] ? '✅ inalterada' : '❌ ALTERADA')." |\n";
        $md .= '| Duração | '.$r['duration_seconds']." s |\n";
        $md .= '| Pendências | '.count($r['issues']).' (precisam de decisão: '.count(array_filter($r['issues'], fn ($i) => $i['needs_decision'])).") |\n";
        if (isset($r['error'])) {
            $md .= '| Erro | '.str_replace('|', '/', $r['error'])." |\n";
        }

        $md .= "\n## Contadores por tabela antiga\n\n| Tabela | Lidos | Importados | Já importados | Mudaram na origem | Não importados | Arquivados | Ignorados | Pendências |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|\n";
        $cont = $r['counters'];
        ksort($cont);
        foreach ($cont as $t => $c) {
            $md .= "| `{$t}` | ".implode(' | ', array_map(fn ($k) => $c[$k] ?? 0, ['read', 'imported', 'unchanged', 'changed', 'skipped', 'archived', 'ignored', 'issues']))." |\n";
        }

        $classes = array_map(fn ($c) => $c->value, IssueClassification::cases());
        $md .= "\n## Classificação dos dados\n\n| Tabela | ".implode(' | ', $classes)." |\n|---|".str_repeat('---:|', count($classes))."\n";
        foreach ($r['classification'] as $t => $c) {
            $md .= "| `{$t}` | ".implode(' | ', array_map(fn ($k) => $c[$k] ?? 0, $classes))." |\n";
        }

        $md .= "\n## Conciliação\n\n| Verificação | Resultado | Detalhe |\n|---|---|---|\n";
        foreach ($r['reconciliation'] ?? [] as $nome => $v) {
            $md .= "| {$nome} | ".($v['ok'] ? '✅' : '❌').' | `'.str_replace('|', '/', json_encode($v['detail'], JSON_UNESCAPED_UNICODE))."` |\n";
        }
        $md .= "\n## Integridade\n\n".(($r['integrity'] ?? []) === [] ? "Todas as regras verificadas: ✅ sem violações.\n" : '❌ '.json_encode($r['integrity'])."\n");

        $md .= "\n## Pendências por código\n\n| Código | Classificação | Quantidade | Decisão? |\n|---|---|---:|---|\n";
        foreach ($this->byCode($r['issues']) as $codigo => $g) {
            $md .= "| `{$codigo}` | {$g['classification']} | {$g['count']} | ".($g['needs_decision'] ? 'sim' : 'não')." |\n";
        }
        $md .= "\n## Tempo por etapa\n\n".implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v} s", array_keys($r['timings'] ?? []), $r['timings'] ?? []))."\n";

        return $md;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function pending(array $r): string
    {
        $pend = array_values(array_filter($r['issues'], fn ($i) => $i['needs_decision']));
        $md = "# Pendências que precisam de decisão\n\n".count($pend)." item(ns). Nada foi mesclado, corrigido ou descartado automaticamente.\n";
        foreach ($this->byCode($pend) as $codigo => $g) {
            $md .= "\n## `{$codigo}` — {$g['count']} item(ns)\n\n{$g['message']}\n\n| Tabela | Registro antigo | Detalhe |\n|---|---|---|\n";
            foreach (array_slice($g['items'], 0, 200) as $i) {
                $md .= "| `{$i['source_table']}` | `".($i['source_id'] ?? '—').'` | '.str_replace(['|', "\n"], ['/', ' '], json_encode($i['context'], JSON_UNESCAPED_UNICODE))." |\n";
            }
            if ($g['count'] > 200) {
                $md .= '| … | '.($g['count'] - 200)." a mais no JSON | |\n";
            }
        }

        return $md;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, array<string, mixed>>
     */
    private function byCode(array $issues): array
    {
        $g = [];
        foreach ($issues as $i) {
            $g[$i['code']] ??= ['count' => 0, 'classification' => $i['classification'], 'needs_decision' => false, 'message' => $i['message'], 'items' => []];
            $g[$i['code']]['count']++;
            $g[$i['code']]['needs_decision'] = $g[$i['code']]['needs_decision'] || $i['needs_decision'];
            $g[$i['code']]['items'][] = $i;
        }
        ksort($g);

        return $g;
    }
}

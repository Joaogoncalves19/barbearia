<?php
// admin_tabs/financeiro.php
// Versão 3.2 - Design Minimalista com Banner Slim de Inteligência Artificial

$csrf_token = generate_csrf_token(); // GERANDO O TOKEN DE SEGURANÇA PARA A ABA

$mes_filtro = $_GET['mes_financeiro'] ?? date('Y-m'); // Padrão: Mês atual
$ano_filtro = substr($mes_filtro, 0, 4);
$mes_filtro_num = substr($mes_filtro, 5, 2);

$pdo = getDB();

// Gera automaticamente as despesas recorrentes do mês visualizado e recarrega a lista.
if (function_exists('garantirDespesasRecorrentes')) {
    garantirDespesasRecorrentes($mes_filtro);
    try {
        $despesasArr = $pdo->query("SELECT * FROM despesas")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// =========================================================
// 0. CARREGAR DADOS ESSENCIAIS (META, VALES E COMISSÕES)
// =========================================================

$meta_faturamento = 15000.00; 
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta_financeira (id INTEGER PRIMARY KEY, valor TEXT)");
    $stmtMeta = $pdo->query("SELECT valor FROM meta_financeira WHERE id = 1");
    if ($rowMeta = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
        $meta_faturamento = (float)$rowMeta['valor'];
    } else {
        $pdo->exec("INSERT INTO meta_financeira (id, valor) VALUES (1, '15000.00')");
    }
} catch (Exception $e) {}

$valesArr = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vales (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_vale TEXT, mes_referencia TEXT, descricao TEXT)");
    $stmtVales = $pdo->query("SELECT * FROM vales");
    if($stmtVales) $valesArr = $stmtVales->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$vales_do_mes = [];
$total_vales_por_barbeiro = [];
foreach($valesArr as $v) {
    if ($v['mes_referencia'] === $mes_filtro) {
        $vales_do_mes[] = $v;
        $bid = $v['barbeiro_id'];
        if (!isset($total_vales_por_barbeiro[$bid])) $total_vales_por_barbeiro[$bid] = 0;
        $total_vales_por_barbeiro[$bid] += (float)$v['valor'];
    }
}
usort($vales_do_mes, fn($a, $b) => strcmp($b['data_vale'], $a['data_vale'])); 

$comissoesPagasArr = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comissoes_pagas (id TEXT PRIMARY KEY, barbeiro_id TEXT, mes_ano TEXT, valor_total_servicos TEXT, valor TEXT, data_pagamento TEXT)");
    $stmtComissoes = $pdo->query("SELECT * FROM comissoes_pagas");
    if($stmtComissoes) $comissoesPagasArr = $stmtComissoes->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}


// =========================================================
// 1. MOTOR DE CÁLCULO: RECEITAS & COMISSÕES
// =========================================================
$receita_servicos = 0;
$receita_produtos = 0;
$faturamento_barbeiros = [];

foreach($agendamentosArr as $ag) {
    if ($ag['status'] === 'concluido' && strpos($ag['data'], $mes_filtro) === 0) {
        $bid = $ag['barbeiro_id'];
        if (!isset($faturamento_barbeiros[$bid])) { 
            $faturamento_barbeiros[$bid] = [
                'servicos' => 0,
                'produtos' => 0,
                'base_comissao' => 0,
                'comissao_total' => 0,
                'comissao_assinatura' => 0,
                'comissao_detalhes' => []
            ];
        }

        $val_serv = 0;
        foreach(explode(',', $ag['servicos_ids']) as $sid) {
            $sid = trim($sid);
            if(isset($servicosArr[$sid])) { $val_serv += (float)$servicosArr[$sid]['valor']; }
            elseif(isset($combosArr[$sid])) { $val_serv += (float)$combosArr[$sid]['valor']; }
        }
        
        $desconto = (float)($ag['desconto_aplicado'] ?? 0);
        $val_serv_liquido = max(0, $val_serv - $desconto);
        $receita_servicos += $val_serv_liquido;
        $faturamento_barbeiros[$bid]['servicos'] += $val_serv_liquido;
        
        $prods = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
        $val_prods = 0;
        if (is_array($prods)) {
            foreach($prods as $p) {
                $val_prods += (float)$p['valor'];
            }
        }
        $receita_produtos += $val_prods;
        $faturamento_barbeiros[$bid]['produtos'] += $val_prods;

        $calculoComissao = calcularComissaoAtendimento(
            $ag,
            $barbeirosArr[$bid] ?? [],
            $val_serv,
            $val_prods
        );
        $faturamento_barbeiros[$bid]['base_comissao'] += $calculoComissao['base_comissao_servicos'];
        if (!empty(($barbeirosArr[$bid] ?? [])['comissao_produtos'])) {
            $faturamento_barbeiros[$bid]['base_comissao'] += $val_prods;
        }
        $faturamento_barbeiros[$bid]['comissao_total'] += $calculoComissao['comissao_total'];
        if ($calculoComissao['eh_assinatura'] && $calculoComissao['comissao_servicos'] > 0) {
            $faturamento_barbeiros[$bid]['comissao_assinatura'] += $calculoComissao['comissao_servicos'];
        }

        // Detalhamento por atendimento: base de cálculo e comissão (avulso + assinatura + produtos)
        $ganhaProd = !empty(($barbeirosArr[$bid] ?? [])['comissao_produtos']);
        $base_atend = (float)$calculoComissao['base_comissao_servicos'] + ($ganhaProd ? $val_prods : 0);
        if ($base_atend > 0 || $calculoComissao['comissao_total'] > 0) {
            $nomes_serv = [];
            foreach (explode(',', $ag['servicos_ids']) as $sid) {
                $sid = trim($sid);
                if (isset($servicosArr[$sid])) { $nomes_serv[] = $servicosArr[$sid]['nome']; }
                elseif (isset($combosArr[$sid])) { $nomes_serv[] = $combosArr[$sid]['nome']; }
            }
            $ehAss = (bool)$calculoComissao['eh_assinatura'];
            if ($ehAss) {
                $tipoTxt = ($ag['tipo_desconto'] === 'adesao_plano') ? 'Adesão de plano' : 'Assinante';
                if ($calculoComissao['modo_assinatura'] === 'fixo') {
                    $regraTxt = 'Valor fixo (assinatura)';
                } elseif ($calculoComissao['percentual_aplicado'] !== null) {
                    $regraTxt = rtrim(rtrim(number_format((float)$calculoComissao['percentual_aplicado'], 2, ',', '.'), '0'), ',') . '% sobre R$ ' . number_format($calculoComissao['base_comissao_servicos'], 2, ',', '.');
                } else {
                    $regraTxt = 'Regra de assinatura';
                }
                $baseTxt = 'Valor de tabela (coberto pelo plano)';
            } else {
                $tipoTxt = 'Avulso';
                $percBarb = (float)($barbeirosArr[$bid]['comissao'] ?? 0);
                $regraTxt = rtrim(rtrim(number_format($percBarb, 2, ',', '.'), '0'), ',') . '% sobre R$ ' . number_format($calculoComissao['base_comissao_servicos'], 2, ',', '.');
                $baseTxt = 'Serviços (líquido de descontos)';
            }
            $faturamento_barbeiros[$bid]['comissao_detalhes'][] = [
                'cliente' => $ag['nome'] ?? 'Cliente',
                'data' => $ag['data'] ?? '',
                'servicos' => $nomes_serv ? implode(', ', $nomes_serv) : 'Atendimento',
                'tipo' => $tipoTxt,
                'is_assinatura' => $ehAss,
                'regra' => $regraTxt,
                'base' => $base_atend,
                'base_txt' => $baseTxt,
                'base_produtos' => $ganhaProd ? $val_prods : 0,
                'comissao_servicos' => (float)$calculoComissao['comissao_servicos'],
                'comissao_produtos' => (float)$calculoComissao['comissao_produtos'],
                'comissao' => (float)$calculoComissao['comissao_total'],
            ];
        }
    }
}

$receita_bruta = $receita_servicos + $receita_produtos;
$custo_comissoes = 0;
$comissoes_detalhadas = [];

foreach($barbeirosArr as $bid => $b) {
    $fat_serv = $faturamento_barbeiros[$bid]['servicos'] ?? 0;
    $fat_prod = $faturamento_barbeiros[$bid]['produtos'] ?? 0;
    $ganha_comissao_produtos = !empty($b['comissao_produtos']);
    $fat_total_exibido = $faturamento_barbeiros[$bid]['base_comissao'] ?? 0;
    $comissao = $faturamento_barbeiros[$bid]['comissao_total'] ?? 0;
    $comissao_assinatura = $faturamento_barbeiros[$bid]['comissao_assinatura'] ?? 0;
    $custo_comissoes += $comissao;
    $vales_abatidos = $total_vales_por_barbeiro[$bid] ?? 0;
    $comissao_liquida_a_pagar = max(0, $comissao - $vales_abatidos);
    
    $registro_pagamento = null;
    foreach($comissoesPagasArr as $cp) {
        if ($cp['barbeiro_id'] == $bid && $cp['mes_ano'] == $mes_filtro) {
            $registro_pagamento = $cp;
            break;
        }
    }
    
    $comissoes_detalhadas[$bid] = [
        'nome' => $b['nome'],
        'foto' => $b['foto'] ?? 'uploads/default-profile.jpg',
        'faturamento' => $fat_total_exibido, 
        'comissao_bruta' => $comissao,
        'comissao_assinatura' => $comissao_assinatura,
        'comissao_detalhes' => $faturamento_barbeiros[$bid]['comissao_detalhes'] ?? [],
        'vales' => $vales_abatidos,
        'comissao_liquida' => $comissao_liquida_a_pagar,
        'pago' => $registro_pagamento !== null,
        'registro' => $registro_pagamento,
        'perc' => $b['comissao'] ?? 0,
        'recebe_produtos' => $ganha_comissao_produtos,
        'regra_assinatura' => descreverComissaoAssinatura($b)
    ];
}

// =========================================================
// 2. MOTOR DE CÁLCULO: DESPESAS
// =========================================================
$total_despesas_mes = 0;
$despesas_do_mes = [];
$despesasArr = $despesasArr ?? [];

foreach($despesasArr as $d) {
    if (strpos($d['data_vencimento'], $mes_filtro) === 0) {
        $total_despesas_mes += (float)$d['valor'];
        $despesas_do_mes[] = $d;
    }
}
usort($despesas_do_mes, fn($a, $b) => strcmp($a['data_vencimento'], $b['data_vencimento']));

// =========================================================
// 2.5 RECEITA DE ASSINATURAS, GORJETAS, CMV E CAIXA (novos)
// =========================================================
$inicioMes = $mes_filtro . '-01';
$fimMes = date('Y-m-t', strtotime($inicioMes . ' 00:00:00'));

// Assinaturas (recorrência via Stripe/manual) confirmadas no mês
$receita_assinaturas = 0;
$assinaturasFin = [];
try {
    $assinaturasFin = lerDados('clientes_assinaturas', ['cliente_id', 'plano_id', 'data_inicio', 'data_fim', 'status', 'gateway', 'gateway_subscription_id', 'ultimo_pagamento_id']) ?: [];
    if (function_exists('getPagamentosAssinaturaPeriodo')) {
        foreach (getPagamentosAssinaturaPeriodo($inicioMes, $fimMes, $assinaturasFin, $planosArr ?? []) as $pg) {
            $receita_assinaturas += (float)($pg['valor'] ?? 0);
        }
    }
} catch (Exception $e) {}

// Gorjetas, CMV (custo dos produtos vendidos) e caixa por forma de pagamento
$total_gorjetas = 0;
$cmv_produtos = 0;
$gorjetas_por_barbeiro = [];
$gorjetas_detalhes_por_barbeiro = [];
$caixa_por_forma = ['dinheiro' => 0, 'pix' => 0, 'debito' => 0, 'credito' => 0, 'outro' => 0, 'nao_informado' => 0];
try {
    if (function_exists('garantirColunasComanda')) garantirColunasComanda();
    $stmtCaixa = $pdo->prepare("SELECT barbeiro_id, nome, data, servicos_ids, produtos_vendidos, desconto_aplicado, gorjeta, forma_pagamento FROM agendamentos WHERE status = 'concluido' AND substr(data,1,7) = ?");
    $stmtCaixa->execute([$mes_filtro]);
    foreach ($stmtCaixa->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $g = max(0, (float)($row['gorjeta'] ?? 0));
        $total_gorjetas += $g;
        $bidRow = $row['barbeiro_id'] ?? '';
        if ($bidRow !== '') {
            $gorjetas_por_barbeiro[$bidRow] = ($gorjetas_por_barbeiro[$bidRow] ?? 0) + $g;
            if ($g > 0) {
                $gorjetas_detalhes_por_barbeiro[$bidRow][] = [
                    'cliente' => $row['nome'] ?? 'Cliente',
                    'data' => $row['data'] ?? '',
                    'gorjeta' => $g,
                ];
            }
        }

        $vs = 0;
        foreach (explode(',', (string)$row['servicos_ids']) as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid])) $vs += (float)$servicosArr[$sid]['valor'];
            elseif (isset($combosArr[$sid])) $vs += (float)$combosArr[$sid]['valor'];
        }
        $vs = max(0, $vs - (float)($row['desconto_aplicado'] ?? 0));

        $vp = 0;
        $prodJson = json_decode((string)$row['produtos_vendidos'], true);
        if (is_array($prodJson)) {
            foreach ($prodJson as $p) {
                $vp += (float)($p['valor'] ?? 0);
                $cmv_produtos += (float)($p['custo'] ?? 0); // custo gravado na venda (registros novos)
            }
        }

        $totalAtend = $vs + $vp + $g;
        $fp = $row['forma_pagamento'] ?: 'nao_informado';
        if (!array_key_exists($fp, $caixa_por_forma)) $fp = 'outro';
        $caixa_por_forma[$fp] += $totalAtend;
    }
} catch (Exception $e) {}

// A gorjeta (100% do profissional) é somada ao líquido a pagar da folha.
foreach ($comissoes_detalhadas as $bidGorj => &$detGorj) {
    $gorjBarb = $gorjetas_por_barbeiro[$bidGorj] ?? 0;
    $detGorj['gorjeta'] = $gorjBarb;
    $detGorj['gorjeta_detalhes'] = $gorjetas_detalhes_por_barbeiro[$bidGorj] ?? [];
    $detGorj['comissao_liquida'] += $gorjBarb;
}
unset($detGorj);

// =========================================================
// 3. LUCRO LÍQUIDO E MARGEM (DRE completo)
// =========================================================
$receita_total = $receita_bruta + $receita_assinaturas;
$lucro_liquido = $receita_total - $custo_comissoes - $total_despesas_mes - $cmv_produtos;
$margem_lucro = $receita_total > 0 ? ($lucro_liquido / $receita_total) * 100 : 0;
$percentual_meta = $meta_faturamento > 0 ? min(100, ($receita_total / $meta_faturamento) * 100) : 100;

// =========================================================
// 3.5 EVOLUÇÃO MENSAL (últimos 6 meses) para o gráfico
// =========================================================
$evolucao = [];
$mesesChart = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($inicioMes . " -$i months"));
    $mesesChart[] = $m;
    $evolucao[$m] = ['receita' => 0, 'comissao' => 0, 'despesa' => 0];
}
foreach ($agendamentosArr as $ag) {
    if (($ag['status'] ?? '') !== 'concluido') continue;
    $m = substr((string)($ag['data'] ?? ''), 0, 7);
    if (!isset($evolucao[$m])) continue;
    $vs = 0;
    foreach (explode(',', (string)($ag['servicos_ids'] ?? '')) as $sid) {
        $sid = trim($sid);
        if (isset($servicosArr[$sid])) $vs += (float)$servicosArr[$sid]['valor'];
        elseif (isset($combosArr[$sid])) $vs += (float)$combosArr[$sid]['valor'];
    }
    $vsLiq = max(0, $vs - (float)($ag['desconto_aplicado'] ?? 0));
    $vp = 0;
    $prodJson = json_decode((string)($ag['produtos_vendidos'] ?? ''), true);
    if (is_array($prodJson)) foreach ($prodJson as $p) $vp += (float)($p['valor'] ?? 0);
    $evolucao[$m]['receita'] += $vsLiq + $vp;
    $calc = calcularComissaoAtendimento($ag, $barbeirosArr[$ag['barbeiro_id']] ?? [], $vs, $vp);
    $evolucao[$m]['comissao'] += (float)($calc['comissao_total'] ?? 0);
}
foreach ($despesasArr as $d) {
    $m = substr((string)($d['data_vencimento'] ?? ''), 0, 7);
    if (isset($evolucao[$m])) $evolucao[$m]['despesa'] += (float)($d['valor'] ?? 0);
}
foreach ($mesesChart as $m) {
    $ini = $m . '-01';
    $fim = date('Y-m-t', strtotime($ini));
    if (function_exists('getPagamentosAssinaturaPeriodo')) {
        foreach (getPagamentosAssinaturaPeriodo($ini, $fim, $assinaturasFin, $planosArr ?? []) as $pg) {
            $evolucao[$m]['receita'] += (float)($pg['valor'] ?? 0);
        }
    }
}
$chartLabels = [];
$chartReceita = [];
$chartDespesa = [];
$chartLucro = [];
$meses_curto = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
foreach ($mesesChart as $m) {
    $chartLabels[] = $meses_curto[(int)substr($m, 5, 2)] . '/' . substr($m, 2, 2);
    $rec = $evolucao[$m]['receita'];
    $desp = $evolucao[$m]['despesa'] + $evolucao[$m]['comissao'];
    $chartReceita[] = round($rec, 2);
    $chartDespesa[] = round($desp, 2);
    $chartLucro[] = round($rec - $desp, 2);
}

// =========================================================
// 4. PREPARAÇÃO DE DADOS PARA A IA (CFO)
// =========================================================
$dados_para_ia_financeiro = "Mês de Referência: $mes_filtro_num/$ano_filtro\\n";
$dados_para_ia_financeiro .= "Receita Total (Serviços + Produtos + Assinaturas): R$ " . number_format($receita_total, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "  - Serviços: R$ " . number_format($receita_servicos, 2, ',', '.') . " | Produtos: R$ " . number_format($receita_produtos, 2, ',', '.') . " | Assinaturas: R$ " . number_format($receita_assinaturas, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Custo com Comissões (Equipe): R$ " . number_format($custo_comissoes, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Custo de Produtos Vendidos (CMV): R$ " . number_format($cmv_produtos, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Despesas Operacionais (Fixas/Variáveis): R$ " . number_format($total_despesas_mes, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Gorjetas recebidas (repassadas à equipe): R$ " . number_format($total_gorjetas, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Lucro Líquido: R$ " . number_format($lucro_liquido, 2, ',', '.') . "\\n";
$dados_para_ia_financeiro .= "Margem de Lucro Atual: " . number_format($margem_lucro, 1, ',', '.') . "%\\n";
?>

<style>
    .financeiro-layout { display: flex; flex-direction: column; gap: 20px; animation: fadeIn 0.4s ease-out; }
    .fin-filter-bar { background: white; padding: 15px 25px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-bottom: 5px;}
    .fin-filter-bar h3 { margin: 0; color: #1e293b; display: flex; align-items: center; gap: 10px; font-size: 1.2rem; }
    .fin-filter-bar input[type="month"] { padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'Inter'; font-weight: 600; color: #334155; outline: none; }
    
    .goal-progress-card { background: white; padding: 20px 25px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); margin-bottom: 5px; }
    .goal-header { display: flex; justify-content: space-between; margin-bottom: 12px; align-items: center; }
    .goal-header strong { color: #1e293b; font-size: 1.05rem; display: flex; align-items: center; }
    .goal-header span { font-weight: 800; color: var(--secondary-color, #007bff); font-size: 1.1rem; }
    .goal-bar-bg { width: 100%; background: #f1f5f9; height: 12px; border-radius: 6px; overflow: hidden; }
    .goal-bar-fill { height: 100%; border-radius: 6px; transition: width 1s ease-in-out; }

    .dre-cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .dre-card { background: white; padding: 25px 20px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); position: relative; overflow: hidden; }
    .dre-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
    .dre-card.receita::before { background: #10b981; }
    .dre-card.comissoes::before { background: #f59e0b; }
    .dre-card.despesas::before { background: #ef4444; }
    .dre-card.lucro::before { background: #0ea5e9; }
    
    .dre-title { color: #64748b; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: block; }
    .dre-value { color: #1e293b; font-size: 1.8rem; font-weight: 800; margin: 0; }
    .dre-sub { color: #94a3b8; font-size: 0.8rem; margin-top: 5px; display: block; }
    
    .fin-table-wrapper { background: white; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); overflow: hidden; margin-bottom: 30px;}
    
    .badge-status { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge-pago { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .badge-pendente { background: #fef08a; color: #b45309; border: 1px solid #fde047; }
    .badge-desconto { background: #fef2f2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }
    
    .btn-pagar { background: #10b981; color: white; padding: 8px 15px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; transition: 0.2s; }
    .btn-pagar:hover { background: #059669; transform: translateY(-1px); }

    .prof-info { display: flex; align-items: center; gap: 12px; }
    .prof-foto { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
    
    /* .action-buttons / .btn-action vivem em css/admin_components.css */

    .fin-split { display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px; margin-top: 4px; }
    .fin-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 22px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .fin-panel-title { margin: 0 0 16px; color: #1e293b; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .fin-caixa-list { display: flex; flex-direction: column; gap: 10px; }
    .fin-caixa-item { display: flex; align-items: center; gap: 12px; }
    .fin-caixa-item .fc-icon { width: 40px; height: 40px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 1.05rem; }
    .fin-caixa-item .fc-info { flex: 1; display: flex; flex-direction: column; }
    .fin-caixa-item .fc-info small { color: #64748b; font-size: 0.78rem; }
    .fin-caixa-item .fc-info strong { color: #1e293b; font-size: 1.05rem; }
    .fin-caixa-item .fc-pct { color: #94a3b8; font-size: 0.8rem; font-weight: 700; }
    .fin-caixa-total { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-top: 16px; padding-top: 14px; border-top: 1px dashed #e2e8f0; color: #64748b; font-size: 0.85rem; }
    .fin-caixa-total strong { color: #1e293b; }
    @media (max-width: 1000px) { .fin-split { grid-template-columns: 1fr; } }
</style>

<div class="financeiro-layout" id="financeiro-wrapper">

    <div class="fin-filter-bar">
        <h3><i class="fa fa-chart-pie" style="color: #0ea5e9;"></i> Gestão Financeira</h3>
        <div style="display: flex; gap: 10px;">
            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="tab" value="financeiro">
                <input type="hidden" name="subtab" value="<?= htmlspecialchars($_GET['subtab'] ?? 'dre') ?>">
                <label style="font-weight: 600; color: #64748b;">Mês:</label>
                <input type="month" name="mes_financeiro" value="<?= $mes_filtro ?>" onchange="this.form.submit()">
            </form>
        </div>
    </div>

<?php
// Sub-aba ativa definida pelo servidor (evita "piscar" na primeira sub-aba
// antes do JS trocar, ao voltar de um salvamento com ?subtab=...).
$fsubtabsValidas = ['dre', 'comissoes', 'vales', 'despesas'];
$fsubtabAtiva = $_GET['subtab'] ?? 'dre';
if (!in_array($fsubtabAtiva, $fsubtabsValidas, true)) $fsubtabAtiva = 'dre';
$fsubAtivo = function ($id) use ($fsubtabAtiva) { return $fsubtabAtiva === $id ? 'active' : ''; };
?>
    <div class="modern-sub-tabs">
        <button class="sub-tab-btn <?= $fsubAtivo('dre') ?>" data-subtab="dre"><i class="fa fa-file-invoice-dollar"></i> Resumo DRE</button>
        <button class="sub-tab-btn <?= $fsubAtivo('comissoes') ?>" data-subtab="comissoes"><i class="fa fa-users"></i> Pagamento da Equipe</button>
        <button class="sub-tab-btn <?= $fsubAtivo('vales') ?>" data-subtab="vales"><i class="fa fa-hand-holding-usd"></i> Vales / Adiantamentos</button>
        <button class="sub-tab-btn <?= $fsubAtivo('despesas') ?>" data-subtab="despesas"><i class="fa fa-receipt"></i> Despesas Fixas</button>
    </div>

    <div id="dre" class="sub-tabcontent <?= $fsubAtivo('dre') ?>">
        
        <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #bbf7d0; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(34,197,94,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                <h4 style="margin: 0; color: #166534; display: flex; align-items: center; gap: 8px; font-size: 1.15rem;"><i class="fa fa-wallet"></i> Conselheiro de Fluxo de Caixa (IA)</h4>
                <button id="btn-gerar-auditoria" style="background: #10b981; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: bold; box-shadow: 0 4px 10px rgba(16,185,129,0.2); transition: 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
                    <i class="fa fa-magic"></i> Auditar Caixa
                </button>
            </div>
            <div id="ia-auditoria-text" style="font-size: 0.95rem; color: #14532d; line-height: 1.6;">
                <i class="fa fa-info-circle"></i> Faça uma auditoria rápida no seu Demonstrativo de Resultados (DRE) para receber conselhos práticos de como enxugar despesas e aumentar a margem de lucro do seu negócio.
            </div>
        </div>

        <div class="goal-progress-card">
            <div class="goal-header">
                <strong>
                    <i class="fa fa-trophy" style="color: #f59e0b; margin-right: 8px;"></i> Meta de Faturamento Mensal
                    <button data-modal-target="#modal-meta" style="background: none; border: none; cursor: pointer; color: #94a3b8; font-size: 1rem; margin-left: 10px; transition: 0.2s;" title="Editar Meta" onmouseover="this.style.color='#0ea5e9'" onmouseout="this.style.color='#94a3b8'"><i class="fa fa-edit"></i></button>
                </strong>
                <span><?= number_format($percentual_meta, 1, ',', '.') ?>%</span>
            </div>
            <div class="goal-bar-bg">
                <div class="goal-bar-fill" style="width: <?= $percentual_meta ?>%; background: <?= $percentual_meta >= 100 ? '#10b981' : 'linear-gradient(90deg, #3b82f6, #0ea5e9)' ?>;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.8rem; color: #94a3b8; font-weight: 600;">
                <span>R$ <?= number_format($receita_bruta, 2, ',', '.') ?> atingido</span>
                <span>Objetivo: R$ <?= number_format($meta_faturamento, 2, ',', '.') ?></span>
            </div>
        </div>

        <div class="dre-cards-grid">
            <div class="dre-card receita">
                <span class="dre-title">Receita Total</span>
                <h3 class="dre-value">R$ <?= number_format($receita_total, 2, ',', '.') ?></h3>
                <span class="dre-sub">Serv: R$ <?= number_format($receita_servicos, 0, ',', '.') ?> · Prod: R$ <?= number_format($receita_produtos, 0, ',', '.') ?> · Assin: R$ <?= number_format($receita_assinaturas, 0, ',', '.') ?></span>
            </div>
            <div class="dre-card comissoes">
                <span class="dre-title">Custo Comissões</span>
                <h3 class="dre-value" style="color: #ea580c;"><?= $custo_comissoes > 0 ? '- ' : '' ?>R$ <?= number_format($custo_comissoes, 2, ',', '.') ?></h3>
                <span class="dre-sub">Repassado à equipe<?= $total_gorjetas > 0 ? ' · + R$ ' . number_format($total_gorjetas, 2, ',', '.') . ' em gorjetas' : '' ?></span>
            </div>
            <div class="dre-card despesas">
                <span class="dre-title">Despesas + CMV</span>
                <h3 class="dre-value" style="color: #dc2626;"><?= ($total_despesas_mes + $cmv_produtos) > 0 ? '- ' : '' ?>R$ <?= number_format($total_despesas_mes + $cmv_produtos, 2, ',', '.') ?></h3>
                <span class="dre-sub">Despesas: R$ <?= number_format($total_despesas_mes, 0, ',', '.') ?><?= $cmv_produtos > 0 ? ' · Custo produtos: R$ ' . number_format($cmv_produtos, 0, ',', '.') : '' ?></span>
            </div>
            <div class="dre-card lucro" style="background: <?= $lucro_liquido >= 0 ? '#f0fdf4' : '#fef2f2' ?>; border-color: <?= $lucro_liquido >= 0 ? '#bbf7d0' : '#fecaca' ?>;">
                <span class="dre-title">Lucro Líquido</span>
                <h3 class="dre-value" style="color: <?= $lucro_liquido >= 0 ? '#166534' : '#991b1b' ?>;">R$ <?= number_format($lucro_liquido, 2, ',', '.') ?></h3>
                <span class="dre-sub">Margem de Lucro: <strong><?= number_format($margem_lucro, 1, ',', '.') ?>%</strong></span>
            </div>
        </div>

        <div class="fin-split">
            <div class="fin-panel">
                <h4 class="fin-panel-title"><i class="fa fa-chart-line" style="color:#0ea5e9;"></i> Evolução (últimos 6 meses)</h4>
                <div style="position: relative; height: 300px;"><canvas id="finEvolucaoChart"></canvas></div>
            </div>

            <div class="fin-panel">
                <h4 class="fin-panel-title"><i class="fa fa-cash-register" style="color:#10b981;"></i> Entradas por forma de pagamento</h4>
                <?php
                $formasLabels = [
                    'dinheiro'      => ['Dinheiro', 'fa-money-bill-wave', '#10b981', '#ecfdf5'],
                    'pix'           => ['PIX', 'fa-bolt', '#0ea5e9', '#eff6ff'],
                    'debito'        => ['Cartão de débito', 'fa-credit-card', '#6366f1', '#eef2ff'],
                    'credito'       => ['Cartão de crédito', 'fa-credit-card', '#8b5cf6', '#f5f3ff'],
                    'outro'         => ['Outro', 'fa-ellipsis', '#64748b', '#f1f5f9'],
                    'nao_informado' => ['Não informado', 'fa-circle-question', '#94a3b8', '#f8fafc'],
                ];
                $totalCaixa = array_sum($caixa_por_forma);
                ?>
                <div class="fin-caixa-list">
                    <?php foreach ($formasLabels as $k => $info):
                        if ($caixa_por_forma[$k] <= 0 && !in_array($k, ['dinheiro', 'pix'], true)) continue;
                        $pct = $totalCaixa > 0 ? ($caixa_por_forma[$k] / $totalCaixa) * 100 : 0;
                    ?>
                        <div class="fin-caixa-item">
                            <span class="fc-icon" style="color:<?= $info[2] ?>; background:<?= $info[3] ?>;"><i class="fa <?= $info[1] ?>"></i></span>
                            <div class="fc-info">
                                <small><?= $info[0] ?></small>
                                <strong>R$ <?= number_format($caixa_por_forma[$k], 2, ',', '.') ?></strong>
                            </div>
                            <span class="fc-pct"><?= number_format($pct, 0) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="fin-caixa-total">
                    <span>Total recebido: <strong>R$ <?= number_format($totalCaixa, 2, ',', '.') ?></strong></span>
                    <span>Gorjetas (equipe): <strong>R$ <?= number_format($total_gorjetas, 2, ',', '.') ?></strong></span>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var el = document.getElementById('finEvolucaoChart');
            if (!el || typeof Chart === 'undefined') return;
            if (el._chart) { try { el._chart.destroy(); } catch (e) {} }
            var money = function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
            el._chart = new Chart(el, {
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [
                        { type: 'bar', label: 'Receita', data: <?= json_encode($chartReceita) ?>, backgroundColor: '#10b981', borderRadius: 6, order: 2 },
                        { type: 'bar', label: 'Custos', data: <?= json_encode($chartDespesa) ?>, backgroundColor: '#ef4444', borderRadius: 6, order: 2 },
                        { type: 'line', label: 'Lucro', data: <?= json_encode($chartLucro) ?>, borderColor: '#0ea5e9', backgroundColor: '#0ea5e9', tension: 0.35, fill: false, order: 1, pointRadius: 4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } },
                    scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); } } } }
                }
            });
        })();
        </script>
    </div>

    <div id="comissoes" class="sub-tabcontent <?= $fsubAtivo('comissoes') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-users"></i> Folha de Pagamento & Comissões</h3>
                <p>O sistema já deduz automaticamente os vales/adiantamentos tirados pelo profissional no mês <strong><?= $mes_filtro_num ?>/<?= $ano_filtro ?></strong>.</p>
            </div>
        </div>

        <div class="fin-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr><th>Profissional</th><th>Base p/ Comissão</th><th>Comissão Bruta</th><th>Vales / Adiant.</th><th>Líquido a Pagar</th><th>Gorjetas</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php if(empty($comissoes_detalhadas)): ?>
                    <tr><td colspan="8" class="empty-state">Nenhum profissional cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($comissoes_detalhadas as $bid => $detalhe): ?>
                    <tr>
                        <td data-label="Profissional">
                            <div class="prof-info">
                                <img src="<?= htmlspecialchars($detalhe['foto']) ?>" class="prof-foto">
                                <div>
                                    <strong style="color: #1e293b; display: block;"><?= htmlspecialchars($detalhe['nome']) ?></strong>
                                    <span style="font-size: 0.75rem; color: #64748b;">Regra: <?= $detalhe['perc'] ?>%</span>
                                    <span class="commission-rule-summary"><i class="fa fa-crown"></i> <?= htmlspecialchars($detalhe['regra_assinatura']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td data-label="Base p/ Comissão">
                            R$ <?= number_format($detalhe['faturamento'], 2, ',', '.') ?>
                            <?php if (!empty($detalhe['comissao_detalhes'])): ?>
                                <button type="button" class="btn-ver-origem" data-detalhe-prof="<?= htmlspecialchars($bid) ?>" data-focus="base"><i class="fa fa-magnifying-glass"></i> ver origem</button>
                            <?php endif; ?>
                        </td>
                        <td data-label="Comissão Bruta">
                            R$ <?= number_format($detalhe['comissao_bruta'], 2, ',', '.') ?>
                            <?php if ($detalhe['comissao_assinatura'] > 0): ?>
                                <small class="commission-plan-detail"><i class="fa fa-crown"></i> R$ <?= number_format($detalhe['comissao_assinatura'], 2, ',', '.') ?> de assinaturas</small>
                            <?php endif; ?>
                            <?php if (!empty($detalhe['comissao_detalhes'])): ?>
                                <button type="button" class="btn-ver-origem" data-detalhe-prof="<?= htmlspecialchars($bid) ?>" data-focus="comissao"><i class="fa fa-magnifying-glass"></i> ver origem</button>
                            <?php endif; ?>
                        </td>
                        <td data-label="Vales">
                            <?php if($detalhe['vales'] > 0): ?>
                                <span class="badge-desconto">- R$ <?= number_format($detalhe['vales'], 2, ',', '.') ?></span>
                            <?php else: ?>
                                <span style="color: #94a3b8;">R$ 0,00</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Líquido">
                            <?php $gorj_bid = $detalhe['gorjeta'] ?? 0; ?>
                            <strong style="color: #059669; font-size: 1.1rem;">R$ <?= number_format($detalhe['comissao_liquida'], 2, ',', '.') ?></strong>
                            <?php if ($gorj_bid > 0): ?>
                                <small class="commission-plan-detail" style="color:#8b5cf6;"><i class="fa fa-hand-holding-heart"></i> Inclui R$ <?= number_format($gorj_bid, 2, ',', '.') ?> de gorjeta</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Gorjetas">
                            <?php if ($gorj_bid > 0): ?>
                                <span style="color: #8b5cf6; font-weight: 700;" title="100% do profissional — somada ao líquido a pagar"><i class="fa fa-hand-holding-heart"></i> R$ <?= number_format($gorj_bid, 2, ',', '.') ?></span>
                                <?php if (!empty($detalhe['gorjeta_detalhes'])): ?>
                                    <button type="button" class="btn-ver-origem" data-detalhe-prof="<?= htmlspecialchars($bid) ?>" data-focus="gorjetas"><i class="fa fa-magnifying-glass"></i> ver origem</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #cbd5e1;">R$ 0,00</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if($detalhe['pago']): ?>
                                <span class="badge-status badge-pago"><i class="fa fa-check"></i> Pago</span>
                            <?php else: ?>
                                <span class="badge-status badge-pendente"><i class="fa fa-clock"></i> Pendente</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <?php if($detalhe['pago']): ?>
                                    <a href="imprimir_recibo_comissao.php?id=<?= $detalhe['registro']['id'] ?>" target="_blank" class="btn-action" style="background: #0ea5e9;" title="Imprimir Recibo"><i class="fa fa-print"></i></a>
                                    <a href="?action=excluir_pagamento_comissao&id=<?= $detalhe['registro']['id'] ?>&tab=financeiro&subtab=comissoes&csrf_token=<?= $csrf_token ?>" class="btn-action" style="background: #ef4444;" onclick="return confirm('Desfazer este pagamento?')" title="Estornar Pagamento"><i class="fa fa-undo"></i></a>
                                <?php else: ?>
                                    <button class="btn-pagar btn-abrir-comissao" data-modal-target="#modal-pagar-comissao" data-id="<?= $bid ?>" data-nome="<?= htmlspecialchars($detalhe['nome']) ?>" data-fat="<?= $detalhe['faturamento'] ?>" data-com="<?= $detalhe['comissao_liquida'] ?>" data-vales="<?= $detalhe['vales'] ?>" data-gorjeta="<?= $detalhe['gorjeta'] ?? 0 ?>"><i class="fa fa-money-bill-wave"></i> Pagar Agora</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Templates ocultos com a origem de Base / Comissão / Gorjetas por profissional -->
        <div id="prof-detalhe-store" style="display:none;">
            <?php foreach ($comissoes_detalhadas as $bid => $detalhe): ?>
                <?php if (empty($detalhe['comissao_detalhes']) && empty($detalhe['gorjeta_detalhes'])) continue; ?>
                <div class="prof-detalhe-tpl" data-bid="<?= htmlspecialchars($bid) ?>" data-nome="<?= htmlspecialchars($detalhe['nome']) ?>">

                    <!-- BASE P/ COMISSÃO -->
                    <div class="prof-detalhe-secao" data-secao="base">
                        <div class="prof-detalhe-head"><i class="fa fa-scale-balanced"></i> Base p/ Comissão <span>R$ <?= number_format($detalhe['faturamento'], 2, ',', '.') ?></span></div>
                        <?php if (empty($detalhe['comissao_detalhes'])): ?>
                            <div class="fid-hist-empty">Sem base no período.</div>
                        <?php else: foreach ($detalhe['comissao_detalhes'] as $det): if ($det['base'] <= 0) continue; ?>
                            <div class="commission-plan-row">
                                <div class="commission-plan-row-main">
                                    <strong><?= htmlspecialchars($det['cliente']) ?></strong>
                                    <span class="commission-plan-badge <?= $det['is_assinatura'] ? 'is-plan' : 'is-avulso' ?>"><?php if ($det['is_assinatura']): ?><i class="fa fa-crown"></i> <?php endif; ?><?= htmlspecialchars($det['tipo']) ?></span>
                                </div>
                                <div class="commission-plan-row-sub">
                                    <?= $det['data'] ? date('d/m/Y', strtotime($det['data'])) : '' ?> · <?= htmlspecialchars($det['servicos']) ?><br>
                                    <span class="commission-plan-rule"><?= htmlspecialchars($det['base_txt']) ?><?php if ($det['base_produtos'] > 0): ?> + R$ <?= number_format($det['base_produtos'], 2, ',', '.') ?> produtos<?php endif; ?></span>
                                    <strong class="commission-plan-amount neutro">R$ <?= number_format($det['base'], 2, ',', '.') ?></strong>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- COMISSÃO BRUTA -->
                    <div class="prof-detalhe-secao" data-secao="comissao">
                        <div class="prof-detalhe-head"><i class="fa fa-list-ul"></i> Comissão Bruta <span>R$ <?= number_format($detalhe['comissao_bruta'], 2, ',', '.') ?></span></div>
                        <?php if (empty($detalhe['comissao_detalhes'])): ?>
                            <div class="fid-hist-empty">Sem comissão no período.</div>
                        <?php else: foreach ($detalhe['comissao_detalhes'] as $det): if ($det['comissao'] <= 0) continue; ?>
                            <div class="commission-plan-row">
                                <div class="commission-plan-row-main">
                                    <strong><?= htmlspecialchars($det['cliente']) ?></strong>
                                    <span class="commission-plan-badge <?= $det['is_assinatura'] ? 'is-plan' : 'is-avulso' ?>"><?php if ($det['is_assinatura']): ?><i class="fa fa-crown"></i> <?php endif; ?><?= htmlspecialchars($det['tipo']) ?></span>
                                </div>
                                <div class="commission-plan-row-sub">
                                    <?= $det['data'] ? date('d/m/Y', strtotime($det['data'])) : '' ?> · <?= htmlspecialchars($det['servicos']) ?><br>
                                    <span class="commission-plan-rule"><?= htmlspecialchars($det['regra']) ?><?php if ($det['comissao_produtos'] > 0): ?> + R$ <?= number_format($det['comissao_produtos'], 2, ',', '.') ?> produtos<?php endif; ?></span>
                                    <strong class="commission-plan-amount">+ R$ <?= number_format($det['comissao'], 2, ',', '.') ?></strong>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                        <?php if ($detalhe['comissao_assinatura'] > 0): ?>
                            <div class="commission-plan-summary"><i class="fa fa-crown"></i> Sendo R$ <?= number_format($detalhe['comissao_assinatura'], 2, ',', '.') ?> de assinaturas.</div>
                        <?php endif; ?>
                    </div>

                    <!-- GORJETAS -->
                    <div class="prof-detalhe-secao" data-secao="gorjetas">
                        <div class="prof-detalhe-head"><i class="fa fa-hand-holding-heart"></i> Gorjetas <span>R$ <?= number_format($detalhe['gorjeta'] ?? 0, 2, ',', '.') ?></span></div>
                        <?php if (empty($detalhe['gorjeta_detalhes'])): ?>
                            <div class="fid-hist-empty">Sem gorjetas no período.</div>
                        <?php else: foreach ($detalhe['gorjeta_detalhes'] as $g): ?>
                            <div class="commission-plan-row">
                                <div class="commission-plan-row-main">
                                    <strong><?= htmlspecialchars($g['cliente']) ?></strong>
                                    <span class="commission-plan-badge is-gorjeta"><i class="fa fa-hand-holding-heart"></i> Gorjeta</span>
                                </div>
                                <div class="commission-plan-row-sub">
                                    <?= $g['data'] ? date('d/m/Y', strtotime($g['data'])) : '' ?>
                                    <strong class="commission-plan-amount gorjeta">+ R$ <?= number_format($g['gorjeta'], 2, ',', '.') ?></strong>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                        <div class="commission-plan-summary" style="color:#6d28d9; border-top-color:#ddd6fe;"><i class="fa fa-circle-info"></i> Gorjetas são 100% do profissional e já entram no líquido a pagar.</div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal: origem de Base / Comissão / Gorjetas -->
    <div class="prof-modal-overlay" id="modal-detalhe-prof">
        <div class="prof-modal">
            <div class="prof-modal-head">
                <h4><i class="fa fa-receipt" style="color:#10b981;"></i> Origem — <span id="prof-detalhe-nome"></span></h4>
                <button type="button" class="fid-modal-close" id="prof-detalhe-close">&times;</button>
            </div>
            <div class="prof-modal-body" id="prof-detalhe-body"></div>
        </div>
    </div>

    <div id="vales" class="sub-tabcontent <?= $fsubAtivo('vales') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-hand-holding-usd"></i> Vales e Adiantamentos</h3>
                <p>Registre saques antecipados dos barbeiros. Eles serão descontados automaticamente do fechamento mensal.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-vale" style="background-color: #f59e0b;"><i class="fa fa-plus-circle"></i> Lançar Novo Vale</button>
        </div>

        <div class="fin-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr><th>Data</th><th>Profissional</th><th>Motivo / Descrição</th><th>Valor</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php if(empty($vales_do_mes)): ?>
                    <tr><td colspan="5" class="empty-state">Nenhum vale registrado neste mês.</td></tr>
                <?php else: ?>
                    <?php foreach ($vales_do_mes as $v): ?>
                    <tr>
                        <td data-label="Data"><strong style="color: #475569;"><i class="fa fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($v['data_vale'])) ?></strong></td>
                        <td data-label="Profissional">
                            <strong style="color: #1e293b;"><?= htmlspecialchars($barbeirosArr[$v['barbeiro_id']]['nome'] ?? 'Desconhecido') ?></strong>
                        </td>
                        <td data-label="Descrição"><?= htmlspecialchars($v['descricao']) ?></td>
                        <td data-label="Valor"><strong style="color: #ea580c; font-size: 1.1rem;">- R$ <?= number_format((float)$v['valor'], 2, ',', '.') ?></strong></td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <a href="?action=excluir_vale&id=<?= $v['id'] ?>&tab=financeiro&subtab=vales&csrf_token=<?= $csrf_token ?>" class="btn-action" style="background: #ef4444;" onclick="return confirm('Excluir este vale?')"><i class="fa fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="despesas" class="sub-tabcontent <?= $fsubAtivo('despesas') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-receipt"></i> Contas e Despesas Operacionais</h3>
                <p>Registre aluguel, luz, compra de insumos e outras despesas que vencem no mês <strong><?= $mes_filtro_num ?>/<?= $ano_filtro ?></strong>.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-despesa" style="background-color: #ef4444;"><i class="fa fa-plus-circle"></i> Nova Despesa</button>
        </div>

        <div class="fin-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr><th>Vencimento</th><th>Descrição / Categoria</th><th>Valor</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php if(empty($despesas_do_mes)): ?>
                    <tr><td colspan="5" class="empty-state">Nenhuma despesa registrada para este mês.</td></tr>
                <?php else: ?>
                    <?php foreach ($despesas_do_mes as $d): ?>
                    <tr>
                        <td data-label="Vencimento"><strong style="color: #475569;"><i class="fa fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($d['data_vencimento'])) ?></strong></td>
                        <td data-label="Descrição">
                            <strong style="color: #1e293b; display: block; margin-bottom: 4px;"><?= htmlspecialchars($d['descricao']) ?></strong>
                            <span style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;"><?= htmlspecialchars($d['categoria']) ?></span>
                            <?php if (!empty($d['recorrente']) || !empty($d['recorrencia_origem'])): ?>
                                <span style="background: #eef2ff; color: #4338ca; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;" title="Despesa fixa mensal"><i class="fa fa-repeat"></i> Recorrente</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Valor"><strong style="color: #dc2626; font-size: 1.1rem;">R$ <?= number_format((float)$d['valor'], 2, ',', '.') ?></strong></td>
                        <td data-label="Status">
                            <?php if($d['status'] === 'pago'): ?>
                                <span class="badge-status badge-pago"><i class="fa fa-check"></i> Pago</span>
                            <?php else: ?>
                                <span class="badge-status badge-pendente"><i class="fa fa-exclamation-circle"></i> Pendente</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <?php if($d['status'] !== 'pago'): ?>
                                    <a href="?action=marcar_despesa_paga&id=<?= $d['id'] ?>&tab=financeiro&subtab=despesas&csrf_token=<?= $csrf_token ?>" class="btn-action" style="background: #10b981;" title="Marcar como Pago" onclick="return confirm('Confirmar pagamento desta despesa?')"><i class="fa fa-check"></i></a>
                                <?php endif; ?>
                                <button class="btn-action btn-editar-despesa" style="background: #334155;" data-modal-target="#modal-despesa" data-type="despesa" data-id="<?= $d['id'] ?>" data-desc="<?= htmlspecialchars($d['descricao']) ?>" data-val="<?= $d['valor'] ?>" data-venc="<?= $d['data_vencimento'] ?>" data-cat="<?= htmlspecialchars($d['categoria']) ?>" data-status="<?= $d['status'] ?>" data-recorrente="<?= !empty($d['recorrente']) ? '1' : '0' ?>"><i class="fa fa-edit"></i></button>
                                <a href="?action=excluir_despesa&id=<?= $d['id'] ?>&tab=financeiro&subtab=despesas&csrf_token=<?= $csrf_token ?>" class="btn-action" style="background: #ef4444;" onclick="return confirm('Excluir esta despesa permanentemente?')"><i class="fa fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div id="modal-meta" class="modal-overlay">
    <div class="modal-content" style="max-width: 400px;">
        <button class="modal-close">&times;</button>
        <div class="modern-modal-header" style="padding: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px; background: transparent;">
            <h3 style="margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <i class="fa fa-bullseye" style="color: #0ea5e9;"></i> Definir Meta Mensal
            </h3>
        </div>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="salvar_meta_financeira">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label>Objetivo de Faturamento (R$)</label>
                <input type="number" name="meta_faturamento" step="0.01" value="<?= $meta_faturamento ?>" class="modern-input" required>
                <small style="color:#64748b; margin-top:5px; display:block;">Defina o valor que a barbearia pretende faturar este mês.</small>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 15px; background: #0ea5e9;">
                <i class="fa fa-save"></i> Salvar Nova Meta
            </button>
        </form>
    </div>
</div>

<div id="modal-despesa" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <button class="modal-close">&times;</button>
        <div class="modern-modal-header" style="padding: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px; background: transparent;">
            <h3 style="margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <i class="fa fa-receipt" style="color: #ef4444;"></i> Lançar Despesa
            </h3>
        </div>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="salvar_despesa">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="id" id="mod_desp_id" value="">
            
            <div class="form-group">
                <label>Descrição (Ex: Conta de Luz)</label>
                <input type="text" name="descricao" id="mod_desp_desc" class="modern-input" required>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label>Valor (R$)</label>
                    <input type="number" name="valor" id="mod_desp_val" step="0.01" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>Vencimento</label>
                    <input type="date" name="data_vencimento" id="mod_desp_venc" class="modern-input" required>
                </div>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label>Categoria</label>
                    <select name="categoria" id="mod_desp_cat" class="modern-input" required>
                        <option value="Fixa (Aluguel, Luz, etc)">Fixa (Aluguel, Luz, etc)</option>
                        <option value="Insumos/Produtos">Compra de Insumos/Produtos</option>
                        <option value="Marketing/Software">Marketing / Sistema</option>
                        <option value="Impostos/Taxas">Impostos / Taxas</option>
                        <option value="Outros">Outros / Variáveis</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status Atual</label>
                    <select name="status" id="mod_desp_status" class="modern-input" required>
                        <option value="pendente">A Pagar (Pendente)</option>
                        <option value="pago">Já Pago</option>
                    </select>
                </div>
            </div>

            <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer;">
                <input type="checkbox" name="recorrente" id="mod_desp_recorrente" value="1" style="margin-top:3px;">
                <span style="font-size:.85rem; color:#475569;">
                    <strong>Despesa fixa mensal (recorrente)</strong><br>
                    <small style="color:#94a3b8;">O sistema recria esta despesa automaticamente todo mês (ex.: aluguel, luz), sem precisar relançar.</small>
                </span>
            </label>

            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 15px; background: #ef4444;">
                <i class="fa fa-save"></i> Salvar Despesa
            </button>
        </form>
    </div>
</div>

<div id="modal-vale" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <button class="modal-close">&times;</button>
        <div class="modern-modal-header" style="padding: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px; background: transparent;">
            <h3 style="margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <i class="fa fa-hand-holding-usd" style="color: #f59e0b;"></i> Lançar Vale / Adiantamento
            </h3>
        </div>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="salvar_vale">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label>Barbeiro / Profissional</label>
                <select name="barbeiro_id" class="modern-input" required>
                    <option value="">Selecione quem está pegando o vale...</option>
                    <?php foreach($barbeirosArr as $bid => $b): ?>
                        <option value="<?= htmlspecialchars($bid) ?>"><?= htmlspecialchars($b['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label>Valor Solicitado (R$)</label>
                    <input type="number" name="valor" step="0.01" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>Data da Retirada</label>
                    <input type="date" name="data_vale" value="<?= date('Y-m-d') ?>" class="modern-input" required>
                </div>
            </div>

            <div class="form-group">
                <label>Descrição Opcional</label>
                <input type="text" name="descricao" placeholder="Ex: Adiantamento para gasolina" class="modern-input">
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 15px; background: #f59e0b;">
                <i class="fa fa-save"></i> Registrar Vale
            </button>
        </form>
    </div>
</div>

<div id="modal-pagar-comissao" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px;">
        <button class="modal-close">&times;</button>
        <div class="modern-modal-header" style="padding: 0 0 15px 0; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px; background: transparent;">
            <h3 style="margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <i class="fa fa-money-bill-wave" style="color: #10b981;"></i> Registrar Pagamento
            </h3>
        </div>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="pagar_comissao">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="barbeiro_id" id="pay_bid" value="">
            <input type="hidden" name="mes_ano" value="<?= $mes_filtro ?>">
            <input type="hidden" name="valor_total_servicos" id="pay_fat_hidden" value="">
            <input type="hidden" name="valor_comissao" id="pay_com_hidden" value="">
            <input type="hidden" name="valor_gorjeta" id="pay_gorjeta_hidden" value="">
            
            <p style="color: #64748b; font-size: 0.95rem; margin-top: 0;">Você está prestes a registrar o pagamento de folha referente a <strong><?= $mes_filtro_num ?>/<?= $ano_filtro ?></strong>.</p>
            
            <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span style="color: #64748b;">Profissional:</span>
                    <strong style="color: #1e293b;" id="pay_nome">-</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span style="color: #64748b;">Base para Comissão:</span>
                    <strong style="color: #1e293b;">R$ <span id="pay_fat">-</span></strong>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span style="color: #64748b;">Vales Abatidos:</span>
                    <strong style="color: #ef4444;">- R$ <span id="pay_vales">-</span></strong>
                </div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;" id="pay_gorjeta_row" hidden>
                    <span style="color: #64748b;">Gorjetas:</span>
                    <strong style="color: #8b5cf6;">+ R$ <span id="pay_gorjeta">-</span></strong>
                </div>

                <div style="display: flex; justify-content: space-between; border-top: 1px solid #cbd5e1; padding-top: 10px; margin-top: 10px;">
                    <span style="color: #1e293b; font-weight: 600;">Líquido a Pagar:</span>
                    <strong style="color: #10b981; font-size: 1.3rem;">R$ <span id="pay_com">-</span></strong>
                </div>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; background: #10b981;">
                <i class="fa fa-check-circle"></i> Confirmar Pagamento
            </button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Lógica das Tabs do Financeiro
    const finTabContainer = document.querySelector('#financeiro-wrapper .modern-sub-tabs');
    if (finTabContainer) {
        const subTabBtns = finTabContainer.querySelectorAll('.sub-tab-btn');
        
        subTabBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault(); 
                subTabBtns.forEach(b => b.classList.remove('active'));
                
                const allSubContents = document.querySelectorAll('#financeiro-wrapper .sub-tabcontent');
                allSubContents.forEach(content => { content.classList.remove('active'); });
                
                const currentBtn = e.target.closest('.sub-tab-btn');
                currentBtn.classList.add('active');
                
                const targetId = currentBtn.getAttribute('data-subtab');
                const targetContent = document.getElementById(targetId);
                if (targetContent) { targetContent.classList.add('active'); }
                
                const url = new URL(window.location);
                url.searchParams.set('subtab', targetId);
                window.history.pushState({}, '', url);
            });
        });

        // A sub-aba correta já vem marcada como "active" pelo servidor (via ?subtab=),
        // então não é preciso simular clique aqui — isso evita o "piscar" na primeira
        // sub-aba antes de trocar para a sub-aba salva.
    }

    // Usando delegação de eventos para interceptar os cliques
    document.body.addEventListener('click', function(e) {
        
        // 2. Editar Despesa
        const btnEditarDespesa = e.target.closest('.btn-editar-despesa');
        if (btnEditarDespesa) {
            setTimeout(() => {
                document.getElementById('mod_desp_id').value = btnEditarDespesa.dataset.id || '';
                document.getElementById('mod_desp_desc').value = btnEditarDespesa.dataset.desc || '';
                document.getElementById('mod_desp_val').value = btnEditarDespesa.dataset.val || '';
                document.getElementById('mod_desp_venc').value = btnEditarDespesa.dataset.venc || '';
                document.getElementById('mod_desp_cat').value = btnEditarDespesa.dataset.cat || '';
                document.getElementById('mod_desp_status').value = btnEditarDespesa.dataset.status || '';
            }, 50);
        }

        // 3. Pagar Comissão
        const btnAbrirComissao = e.target.closest('.btn-abrir-comissao');
        if (btnAbrirComissao) {
            setTimeout(() => {
                document.getElementById('pay_bid').value = btnAbrirComissao.dataset.id || '';
                document.getElementById('pay_fat_hidden').value = btnAbrirComissao.dataset.fat || '';
                document.getElementById('pay_com_hidden').value = btnAbrirComissao.dataset.com || '';
                
                document.getElementById('pay_nome').innerText = btnAbrirComissao.dataset.nome || '';
                document.getElementById('pay_fat').innerText = parseFloat(btnAbrirComissao.dataset.fat || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                document.getElementById('pay_com').innerText = parseFloat(btnAbrirComissao.dataset.com || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                
                const valesSpan = document.getElementById('pay_vales');
                if(valesSpan) {
                    valesSpan.innerText = parseFloat(btnAbrirComissao.dataset.vales || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                }

                const gorjeta = parseFloat(btnAbrirComissao.dataset.gorjeta || 0);
                const gorjetaHidden = document.getElementById('pay_gorjeta_hidden');
                if (gorjetaHidden) gorjetaHidden.value = btnAbrirComissao.dataset.gorjeta || '0';
                const gorjetaRow = document.getElementById('pay_gorjeta_row');
                if (gorjetaRow) {
                    if (gorjeta > 0) {
                        document.getElementById('pay_gorjeta').innerText = gorjeta.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        gorjetaRow.hidden = false;
                    } else {
                        gorjetaRow.hidden = true;
                    }
                }
            }, 50);
        }
        
        // 4. Limpar Modal ao criar Nova Despesa
        const btnNovaDespesa = e.target.closest('button[data-modal-target="#modal-despesa"]:not(.btn-editar-despesa)');
        if (btnNovaDespesa) {
            setTimeout(() => {
                document.getElementById('mod_desp_id').value = '';
                document.getElementById('mod_desp_desc').value = '';
                document.getElementById('mod_desp_val').value = '';
                document.getElementById('mod_desp_venc').value = '';
                document.getElementById('mod_desp_cat').selectedIndex = 0;
                document.getElementById('mod_desp_status').selectedIndex = 0;
            }, 50);
        }
    });

    // 5. Integração com IA (Conselheiro Financeiro)
    const btnAuditoria = document.getElementById('btn-gerar-auditoria');
    const contentAuditoria = document.getElementById('ia-auditoria-text');
    const dadosParaIA = `<?= $dados_para_ia_financeiro ?>`;

    if (btnAuditoria) {
        btnAuditoria.addEventListener('click', async function() {
            // Inicia o estado de loading
            btnAuditoria.disabled = true;
            btnAuditoria.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Auditando...';
            contentAuditoria.innerHTML = '<div style="text-align:center; padding: 10px; color:#166534;"><i class="fa fa-circle-notch fa-spin fa-2x" style="margin-bottom: 10px;"></i><br>A Inteligência Artificial está processando as suas despesas...</div>';
            
            try {
                const response = await fetch('ajax_gemini.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'conselheiro_financeiro',
                        dados_financeiros: dadosParaIA
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const formattedText = result.resposta.replace(/\n/g, '<br>');
                    contentAuditoria.innerHTML = '<strong style="display:block; margin-bottom: 10px; color:#14532d; font-size:1.05rem;"><i class="fa fa-check-circle"></i> Parecer do Diretor Financeiro (IA):</strong>' + formattedText;
                } else {
                    contentAuditoria.innerHTML = '<span style="color:#ef4444; font-weight:bold;"><i class="fa fa-exclamation-triangle"></i> Erro: ' + result.error + '</span>';
                }
            } catch (error) {
                contentAuditoria.innerHTML = '<span style="color:#ef4444; font-weight:bold;"><i class="fa fa-wifi"></i> Erro de conexão com o servidor ou timeout. Tente novamente.</span>';
                console.error(error);
            } finally {
                // Restaura o estado do botão
                btnAuditoria.disabled = false;
                btnAuditoria.innerHTML = '<i class="fa fa-sync-alt"></i> Atualizar IA';
            }
        });
    }

    // Modal de origem (Base / Comissão / Gorjetas) por profissional
    (function () {
        const overlay = document.getElementById('modal-detalhe-prof');
        const body = document.getElementById('prof-detalhe-body');
        const nomeEl = document.getElementById('prof-detalhe-nome');
        const closeBtn = document.getElementById('prof-detalhe-close');
        if (!overlay || !body) return;

        function abrir(bid, focus) {
            const tpl = document.querySelector('.prof-detalhe-tpl[data-bid="' + (window.CSS && CSS.escape ? CSS.escape(bid) : bid) + '"]');
            if (!tpl) return;
            nomeEl.textContent = tpl.getAttribute('data-nome') || '';
            body.innerHTML = tpl.innerHTML;
            overlay.classList.add('open');
            if (focus) {
                const alvo = body.querySelector('.prof-detalhe-secao[data-secao="' + focus + '"]');
                if (alvo) { alvo.classList.add('destaque'); alvo.scrollIntoView({block: 'start'}); }
            }
        }
        function fechar() { overlay.classList.remove('open'); }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-ver-origem');
            if (btn) { e.preventDefault(); abrir(btn.dataset.detalheProf, btn.dataset.focus); }
        });
        if (closeBtn) closeBtn.addEventListener('click', fechar);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) fechar(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    })();
});
</script>

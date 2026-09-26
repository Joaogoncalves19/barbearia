<?php
// admin_tabs/servicos.php
// Aba "Serviços & Estoque": categorias, serviços avulsos, combos, planos de
// assinatura, produtos/estoque e o kardex de movimentações.
//
// O CSS desta aba é todo escopado em #servicos-wrapper de propósito: o modo
// escuro (css/admin_theme.css) traz uma "rede de segurança" com especificidade
// alta sobre .tabcontent, e o seletor de ID é a forma limpa das regras da aba
// continuarem valendo nos dois temas. As cores saem de tokens --svc-* que são
// remapeados para os tokens --dm-* quando o tema escuro está ativo.

// =========================================================================
// HISTÓRICO DE ESTOQUE (busca + filtro + paginação)
// =========================================================================
$pdo_estoque = getDB();
$pdo_estoque->exec("CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)");

$busca_estoque = trim($_GET['busca_estoque'] ?? '');
$filtro_tipo_estoque = $_GET['filtro_tipo_estoque'] ?? '';
if (!in_array($filtro_tipo_estoque, ['', 'entrada', 'saida'], true)) {
    $filtro_tipo_estoque = '';
}

$where = [];
$params = [];
if ($busca_estoque !== '') {
    $where[] = '(p.nome LIKE ? OR el.motivo LIKE ? OR el.usuario LIKE ?)';
    $termo = '%' . $busca_estoque . '%';
    array_push($params, $termo, $termo, $termo);
}
if ($filtro_tipo_estoque !== '') {
    $where[] = 'el.tipo = ?';
    $params[] = $filtro_tipo_estoque;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$estoque_itemsPerPage = 25;
$estoque_totalItems = 0;
try {
    $stmtCount = $pdo_estoque->prepare("SELECT COUNT(*) FROM estoque_logs el LEFT JOIN produtos p ON el.produto_id = p.id $whereSql");
    $stmtCount->execute($params);
    $estoque_totalItems = (int)$stmtCount->fetchColumn();
} catch (Exception $e) {}

// Totais por tipo respeitando os filtros — resumo do que está sendo listado,
// e não apenas da página atual.
$estoque_mov_entradas = 0; $estoque_mov_saidas = 0;
$estoque_un_entradas = 0;  $estoque_un_saidas = 0;
try {
    $stmtTot = $pdo_estoque->prepare("SELECT el.tipo, COUNT(*) AS n, COALESCE(SUM(el.quantidade), 0) AS q FROM estoque_logs el LEFT JOIN produtos p ON el.produto_id = p.id $whereSql GROUP BY el.tipo");
    $stmtTot->execute($params);
    foreach ($stmtTot->fetchAll(PDO::FETCH_ASSOC) as $linhaTot) {
        if (($linhaTot['tipo'] ?? '') === 'entrada') {
            $estoque_mov_entradas = (int)$linhaTot['n']; $estoque_un_entradas = (int)$linhaTot['q'];
        } elseif (($linhaTot['tipo'] ?? '') === 'saida') {
            $estoque_mov_saidas = (int)$linhaTot['n']; $estoque_un_saidas = (int)$linhaTot['q'];
        }
    }
} catch (Exception $e) {}

$estoque_totalPages = $estoque_totalItems > 0 ? (int)ceil($estoque_totalItems / $estoque_itemsPerPage) : 1;
$estoque_currentPage = max(1, min((int)($_GET['est_page'] ?? 1), $estoque_totalPages));
$estoque_offset = ($estoque_currentPage - 1) * $estoque_itemsPerPage;

$estoqueLogsArr = [];
try {
    $stmtLogs = $pdo_estoque->prepare("SELECT el.*, p.nome as produto_nome FROM estoque_logs el LEFT JOIN produtos p ON el.produto_id = p.id $whereSql ORDER BY el.data_hora DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $v) {
        $stmtLogs->bindValue($i + 1, $v);
    }
    $stmtLogs->bindValue(count($params) + 1, $estoque_itemsPerPage, PDO::PARAM_INT);
    $stmtLogs->bindValue(count($params) + 2, $estoque_offset, PDO::PARAM_INT);
    $stmtLogs->execute();
    $estoqueLogsArr = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$estoque_filtros_ativos = ($busca_estoque !== '' || $filtro_tipo_estoque !== '');

$csrf_token = generate_csrf_token(); // Token CSRF

// =========================================================================
// RESUMO DE ESTOQUE (valor a custo/venda, lucro, esgotados, a repor)
// =========================================================================
$estoque_valor_custo = 0;
$estoque_valor_venda = 0;
$estoque_esgotados = 0;
$estoque_a_repor = 0;
$estoque_unidades = 0;
$estoque_sem_custo = 0;
foreach ($produtosArr as $prodR) {
    $q = (int)($prodR['quantidade'] ?? 0);
    $c = (float)($prodR['custo'] ?? 0);
    $v = (float)($prodR['valor'] ?? 0);
    $min = (int)($prodR['estoque_minimo'] ?? 5);
    $estoque_valor_custo += $q * $c;
    $estoque_valor_venda += $q * $v;
    $estoque_unidades += max(0, $q);
    if ($c <= 0) $estoque_sem_custo++;
    if ($q <= 0) $estoque_esgotados++;
    elseif ($q <= $min) $estoque_a_repor++;
}
$estoque_lucro_potencial = $estoque_valor_venda - $estoque_valor_custo;
$estoque_ok = max(0, count($produtosArr) - $estoque_esgotados - $estoque_a_repor);
$estoque_atencao = $estoque_esgotados + $estoque_a_repor;

// Ordena produtos por urgência (esgotado > a repor > disponível), depois nome.
$produtosOrdenados = $produtosArr;
uasort($produtosOrdenados, function ($a, $b) {
    $rank = function ($p) {
        $q = (int)($p['quantidade'] ?? 0);
        $min = (int)($p['estoque_minimo'] ?? 5);
        if ($q <= 0) return 0;
        if ($q <= $min) return 1;
        return 2;
    };
    $ra = $rank($a); $rb = $rank($b);
    if ($ra !== $rb) return $ra - $rb;
    return strnatcasecmp($a['nome'] ?? '', $b['nome'] ?? '');
});

// =========================================================================
// POPULARIDADE DOS SERVIÇOS (nº realizado + receita) — atendimentos concluídos
// =========================================================================
$servicoStats = [];
$totalServicosRealizados = 0;
foreach (($agendamentosArr ?? []) as $agS) {
    if (($agS['status'] ?? '') !== 'concluido') continue;
    foreach (explode(',', (string)($agS['servicos_ids'] ?? '')) as $sid) {
        $sid = trim($sid);
        if ($sid === '' || !isset($servicosArr[$sid])) continue;
        if (!isset($servicoStats[$sid])) $servicoStats[$sid] = ['qtd' => 0, 'receita' => 0];
        $servicoStats[$sid]['qtd']++;
        $servicoStats[$sid]['receita'] += (float)$servicosArr[$sid]['valor'];
        $totalServicosRealizados++;
    }
}
// Pico usado como referência (100%) da barra de popularidade.
$servicoPicoQtd = 0;
$servicoReceitaTotal = 0;
foreach ($servicoStats as $st) {
    $servicoPicoQtd = max($servicoPicoQtd, (int)$st['qtd']);
    $servicoReceitaTotal += (float)$st['receita'];
}
$servicoValorSoma = 0;
foreach ($servicosArr as $sTk) { $servicoValorSoma += (float)($sTk['valor'] ?? 0); }
$servicoTicketMedio = count($servicosArr) > 0 ? $servicoValorSoma / count($servicosArr) : 0;
$servicoSemUso = 0;
foreach ($servicosArr as $sid => $sTk) { if ((int)($servicoStats[$sid]['qtd'] ?? 0) === 0) $servicoSemUso++; }

// =========================================================================
// COMBOS — preço cheio (soma dos avulsos) e economia oferecida ao cliente
// =========================================================================
$comboInfo = [];
$comboEconomiaMedia = 0;
$comboComDesconto = 0;
foreach ($combosArr as $cid => $c) {
    $servicosDoComboCalc = getServicesFromCombo($c, $servicosArr);
    $cheio = 0; $slotsCombo = 0;
    foreach ($servicosDoComboCalc as $servCalc) {
        $cheio += (float)($servCalc['valor'] ?? 0);
        $slotsCombo += max(1, (int)($servCalc['slots'] ?? 1));
    }
    $valorCombo = (float)($c['valor'] ?? 0);
    $economia = $cheio - $valorCombo;
    $economiaPct = $cheio > 0 ? ($economia / $cheio) * 100 : 0;
    $comboInfo[$cid] = [
        'servicos' => $servicosDoComboCalc,
        'cheio' => $cheio,
        'economia' => $economia,
        'economia_pct' => $economiaPct,
        'slots' => $slotsCombo,
    ];
    if ($economia > 0) { $comboComDesconto++; $comboEconomiaMedia += $economiaPct; }
}
$comboEconomiaMedia = $comboComDesconto > 0 ? $comboEconomiaMedia / $comboComDesconto : 0;

// =========================================================================
// ASSINANTES POR PLANO (ativos) + receita recorrente
// =========================================================================
$assinantesPorPlano = [];
foreach (($assinaturasClientesArr ?? []) as $ass) {
    if (($ass['status'] ?? '') !== 'ativo') continue;
    $pid = $ass['plano_id'] ?? '';
    if ($pid === '') continue;
    $assinantesPorPlano[$pid] = ($assinantesPorPlano[$pid] ?? 0) + 1;
}
$planoAssinantesTotal = array_sum($assinantesPorPlano);
$planoMrr = 0;
foreach ($planosArr as $pidCalc => $plCalc) {
    $planoMrr += (int)($assinantesPorPlano[$pidCalc] ?? 0) * (float)($plCalc['valor'] ?? 0);
}

// =========================================================================
// CATEGORIAS — quantos serviços / combos / produtos cada uma agrupa
// =========================================================================
$categoriaUso = [];
$semCategoriaServicos = 0;
foreach ($servicosArr as $sCat) {
    $cid = $sCat['categoria_id'] ?? '';
    if ($cid === '' || !isset($categoriasArr[$cid])) { $semCategoriaServicos++; continue; }
    $categoriaUso[$cid]['servicos'][] = $sCat['nome'] ?? '';
}
foreach ($combosArr as $cCat) {
    $cid = $cCat['categoria_id'] ?? '';
    if ($cid === '' || !isset($categoriasArr[$cid])) continue;
    $categoriaUso[$cid]['combos'][] = $cCat['nome'] ?? '';
}
foreach ($produtosArr as $pCat) {
    $cid = $pCat['categoria_id'] ?? '';
    if ($cid === '' || !isset($categoriasArr[$cid])) continue;
    $categoriaUso[$cid]['produtos'][] = $pCat['nome'] ?? '';
}

$categoriasOrdenadas = $categoriasArr;
uasort($categoriasOrdenadas, function ($a, $b) {
    $oa = (int)($a['ordem'] ?? 99); $ob = (int)($b['ordem'] ?? 99);
    if ($oa !== $ob) return $oa - $ob;
    return strnatcasecmp($a['nome'] ?? '', $b['nome'] ?? '');
});

// Contagens usadas nos selos das sub-abas e no painel de visão geral.
$nCategorias = count($categoriasArr);
$nServicos   = count($servicosArr);
$nCombos     = count($combosArr);
$nPlanos     = count($planosArr);
$nProdutos   = count($produtosArr);

// Sub-aba ativa definida pelo servidor (evita "piscar" na primeira sub-aba
// antes do JS trocar, ao voltar de um salvamento com ?subtab=...).
$subtabsValidas = ['categorias', 'servicos-individuais', 'combos', 'planos', 'produtos', 'historico-estoque'];
$subtabAtiva = $_GET['subtab'] ?? 'categorias';
if (!in_array($subtabAtiva, $subtabsValidas, true)) $subtabAtiva = 'categorias';
$subAtivo = function ($id) use ($subtabAtiva) { return $subtabAtiva === $id ? 'active' : ''; };

$moeda = function ($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); };
?>

<style>
    /* ==========================================================================
       SERVIÇOS & ESTOQUE
       Tudo escopado em #servicos-wrapper: além de isolar a aba, o seletor de ID
       garante que estas regras vençam a rede de segurança do modo escuro
       (css/admin_theme.css), que usa especificidade alta em .tabcontent.
       ========================================================================== */
    #servicos-wrapper {
        --svc-surface: #ffffff;
        --svc-surface-2: #f8fafc;
        --svc-surface-3: #f1f5f9;
        --svc-border: #e2e8f0;
        --svc-border-soft: #f1f5f9;
        --svc-text: #0f172a;
        --svc-muted: #64748b;
        --svc-faint: #94a3b8;
        --svc-shadow: 0 4px 6px -1px rgba(15, 23, 42, .03);
    }
    html.dark-mode #servicos-wrapper {
        --svc-surface: var(--dm-surface);
        --svc-surface-2: var(--dm-surface-2);
        --svc-surface-3: var(--dm-surface-3);
        --svc-border: var(--dm-border);
        --svc-border-soft: var(--dm-border-soft);
        --svc-text: var(--dm-text);
        --svc-muted: var(--dm-muted);
        --svc-faint: var(--dm-faint);
        --svc-shadow: var(--dm-shadow);
    }

    .servicos-layout { display: flex; flex-direction: column; gap: 18px; animation: fadeIn 0.4s ease-out; }
    #servicos-wrapper .sub-tabcontent { display: none; animation: fadeIn 0.4s ease-out; }
    #servicos-wrapper .sub-tabcontent.active { display: block; }
    #servicos-wrapper .section-header-info p { line-height: 1.4; max-width: 640px; }
    #servicos-wrapper .section-header-bar { margin-bottom: 16px; }

    /* --- Visão geral (topo da aba) ---------------------------------------- */
    #servicos-wrapper .svc-overview { display: grid; grid-template-columns: repeat(auto-fit, minmax(168px, 1fr)); gap: 12px; }
    #servicos-wrapper .svc-stat {
        display: flex; align-items: center; gap: 13px; width: 100%; text-align: left;
        padding: 15px 16px; background: var(--svc-surface); border: 1px solid var(--svc-border);
        border-radius: 14px; box-shadow: var(--svc-shadow); cursor: pointer;
        font-family: inherit; transition: border-color .2s, transform .2s, box-shadow .2s;
    }
    #servicos-wrapper .svc-stat:hover { border-color: var(--svc-faint); transform: translateY(-2px); box-shadow: 0 14px 26px -18px rgba(15, 23, 42, .45); }
    #servicos-wrapper .svc-stat > i {
        width: 42px; height: 42px; flex-shrink: 0; border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    #servicos-wrapper .svc-stat-body { min-width: 0; }
    #servicos-wrapper .svc-stat-value { display: block; color: var(--svc-text); font-size: 1.32rem; font-weight: 800; line-height: 1.15; }
    #servicos-wrapper .svc-stat-label { display: block; margin-top: 2px; color: var(--svc-muted); font-size: .76rem; font-weight: 600; }
    #servicos-wrapper .sv-blue > i { background: #eff6ff; color: #2563eb; }
    #servicos-wrapper .sv-indigo > i { background: #eef2ff; color: #4f46e5; }
    #servicos-wrapper .sv-purple > i { background: #f5f3ff; color: #7c3aed; }
    #servicos-wrapper .sv-amber > i { background: #fffbeb; color: #d97706; }
    #servicos-wrapper .sv-green > i { background: #ecfdf5; color: #059669; }
    #servicos-wrapper .sv-slate > i { background: #f1f5f9; color: #475569; }
    html.dark-mode #servicos-wrapper .svc-stat > i { background: var(--dm-surface-3); }

    /* --- Aviso de estoque -------------------------------------------------- */
    #servicos-wrapper .svc-alerta {
        display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        padding: 14px 18px; border-radius: 14px;
        background: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #f59e0b;
    }
    #servicos-wrapper .svc-alerta > i { color: #d97706; font-size: 1.3rem; }
    #servicos-wrapper .svc-alerta-txt { flex: 1; min-width: 220px; color: #92400e; font-size: .88rem; font-weight: 600; line-height: 1.45; }
    #servicos-wrapper .svc-alerta-txt b { font-weight: 800; }
    #servicos-wrapper .svc-alerta-acoes { display: flex; gap: 8px; flex-wrap: wrap; }
    #servicos-wrapper .svc-alerta-btn {
        padding: 8px 14px; border: 1px solid #f59e0b; border-radius: 8px; background: #fff;
        color: #b45309; font: 700 .8rem 'Inter', sans-serif; cursor: pointer; transition: .15s;
    }
    #servicos-wrapper .svc-alerta-btn:hover { background: #f59e0b; color: #fff; }
    html.dark-mode #servicos-wrapper .svc-alerta { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .35); }
    html.dark-mode #servicos-wrapper .svc-alerta-txt { color: #fcd34d; }
    html.dark-mode #servicos-wrapper .svc-alerta-btn { background: transparent; color: #fcd34d; }
    html.dark-mode #servicos-wrapper .svc-alerta-btn:hover { background: #f59e0b; color: #111827; }

    /* --- Sub-abas com contador -------------------------------------------- */
    #servicos-wrapper .st-count {
        min-width: 21px; padding: 1px 7px; border-radius: 999px;
        background: var(--svc-surface-3); color: var(--svc-muted);
        font-size: .72rem; font-weight: 800; text-align: center;
    }
    #servicos-wrapper .sub-tab-btn.active .st-count { background: rgba(255, 255, 255, .25); color: #fff; }
    #servicos-wrapper .st-count.is-alerta { background: #fee2e2; color: #b91c1c; }
    #servicos-wrapper .sub-tab-btn.active .st-count.is-alerta { background: rgba(255, 255, 255, .9); color: #b91c1c; }

    /* --- Barra de ferramentas (busca / filtros / ordenação) ---------------- */
    #servicos-wrapper .svc-toolbar {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px;
        padding: 12px 14px; background: var(--svc-surface-2); border: 1px solid var(--svc-border); border-radius: 12px;
    }
    #servicos-wrapper .svc-search { position: relative; flex: 1 1 220px; min-width: 180px; }
    #servicos-wrapper .svc-search > i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--svc-faint); pointer-events: none; }
    #servicos-wrapper .svc-search input {
        width: 100%; box-sizing: border-box; height: 42px; padding: 0 14px 0 38px;
        border: 1px solid var(--svc-border); border-radius: 9px; outline: none;
        background: var(--svc-surface); color: var(--svc-text); font: 600 .88rem 'Inter', sans-serif;
        transition: border-color .2s, box-shadow .2s;
    }
    #servicos-wrapper .svc-search input::placeholder { color: var(--svc-faint); font-weight: 500; }
    #servicos-wrapper .svc-search input:focus,
    #servicos-wrapper .svc-toolbar select:focus { border-color: var(--secondary-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--secondary-color) 11%, transparent); }
    #servicos-wrapper .svc-toolbar select {
        height: 42px; padding: 0 12px; border: 1px solid var(--svc-border); border-radius: 9px;
        background: var(--svc-surface); color: var(--svc-text); font: 600 .84rem 'Inter', sans-serif;
        outline: none; cursor: pointer;
    }
    #servicos-wrapper .svc-chips { display: flex; gap: 6px; flex-wrap: wrap; }
    #servicos-wrapper .svc-chip {
        display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px;
        border: 1px solid var(--svc-border); border-radius: 9px; background: var(--svc-surface);
        color: var(--svc-muted); font: 700 .8rem 'Inter', sans-serif; cursor: pointer; transition: .15s;
    }
    #servicos-wrapper .svc-chip:hover { border-color: var(--svc-faint); color: var(--svc-text); }
    #servicos-wrapper .svc-chip b { padding: 0 6px; border-radius: 999px; background: var(--svc-surface-3); color: var(--svc-muted); font-size: .72rem; }
    #servicos-wrapper .svc-chip.active { background: var(--secondary-color, #0363c9); border-color: var(--secondary-color, #0363c9); color: #fff; }
    #servicos-wrapper .svc-chip.active b { background: rgba(255, 255, 255, .25); color: #fff; }
    #servicos-wrapper .svc-chip.chip-warn.active { background: #d97706; border-color: #d97706; }
    #servicos-wrapper .svc-chip.chip-danger.active { background: #dc2626; border-color: #dc2626; }
    #servicos-wrapper .svc-toolbar-count { margin-left: auto; color: var(--svc-muted); font-size: .82rem; font-weight: 600; white-space: nowrap; }
    #servicos-wrapper .svc-toolbar-count strong { color: var(--svc-text); }
    #servicos-wrapper .btn-clear-svc {
        width: 42px; height: 42px; flex-shrink: 0; border: 1px solid var(--svc-border); border-radius: 9px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--svc-muted); background: var(--svc-surface); text-decoration: none; transition: .2s;
    }
    #servicos-wrapper .btn-clear-svc:hover { color: #dc2626; border-color: #fecaca; background: #fff7f7; }

    /* --- Células das tabelas ----------------------------------------------
       As tabelas desta aba têm até 7 colunas; o passo padrão de 22px de
       respiro empurrava os botões de ação para uma segunda linha. */
    #servicos-wrapper .modern-table th { padding: 14px 16px; }
    #servicos-wrapper .modern-table td { padding: 14px 16px; }
    #servicos-wrapper .modern-table td[data-label="Ações"] { white-space: nowrap; }
    #servicos-wrapper .modern-table .action-buttons { flex-wrap: nowrap; }
    #servicos-wrapper .modern-table .badge { white-space: nowrap; }
    #servicos-wrapper .td-nome { color: var(--svc-text); font-size: .97rem; font-weight: 700; }
    #servicos-wrapper .td-sub { display: block; margin-top: 3px; color: var(--svc-faint); font-size: .77rem; font-weight: 500; line-height: 1.35; max-width: 380px; }
    #servicos-wrapper .td-valor { color: #059669; font-size: 1.03rem; font-weight: 800; }
    #servicos-wrapper .td-valor-vip { color: #7c3aed; font-size: 1.03rem; font-weight: 800; }
    #servicos-wrapper .td-riscado { color: var(--svc-faint); font-size: .8rem; font-weight: 600; text-decoration: line-through; }
    #servicos-wrapper .badge { margin: 2px 2px 2px 0; }
    #servicos-wrapper .badge-purple { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
    #servicos-wrapper .badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde047; }
    #servicos-wrapper .badge-lista { display: flex; flex-wrap: wrap; gap: 4px; max-width: 380px; }
    #servicos-wrapper .btn-blue { background: #0284c7; }
    /* No escuro os selos pastel viravam pastilhas brancas — esta aba usa muitos
       deles (serviços de um combo/plano, categorias), então recebem tint próprio. */
    html.dark-mode #servicos-wrapper .badge-gray { background: var(--dm-surface-3); border-color: var(--dm-border); color: var(--dm-muted); }
    html.dark-mode #servicos-wrapper .badge-blue { background: rgba(37, 99, 235, .16); border-color: rgba(37, 99, 235, .35); color: #93c5fd; }
    html.dark-mode #servicos-wrapper .badge-green { background: rgba(5, 150, 105, .16); border-color: rgba(5, 150, 105, .38); color: #6ee7b7; }
    html.dark-mode #servicos-wrapper .badge-red { background: rgba(220, 38, 38, .16); border-color: rgba(220, 38, 38, .38); color: #fca5a5; }
    html.dark-mode #servicos-wrapper .badge-purple { background: rgba(124, 58, 237, .18); border-color: rgba(124, 58, 237, .4); color: #c4b5fd; }
    html.dark-mode #servicos-wrapper .badge-warning { background: rgba(217, 119, 6, .18); border-color: rgba(217, 119, 6, .4); color: #fcd34d; }

    /* Barra de popularidade do serviço */
    #servicos-wrapper .pop-cell { min-width: 130px; }
    #servicos-wrapper .pop-top { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    #servicos-wrapper .pop-qtd { color: var(--svc-text); font-size: .9rem; font-weight: 800; }
    #servicos-wrapper .pop-receita { color: #059669; font-size: .76rem; font-weight: 700; }
    #servicos-wrapper .pop-track { height: 6px; margin-top: 6px; border-radius: 999px; background: var(--svc-surface-3); overflow: hidden; }
    #servicos-wrapper .pop-fill { height: 100%; border-radius: 999px; background: var(--secondary-color, #0363c9); }
    #servicos-wrapper .pop-vazio { color: var(--svc-faint); font-size: .8rem; font-style: italic; }

    /* --- Cartões de categoria --------------------------------------------- */
    #servicos-wrapper .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
    #servicos-wrapper .cat-card {
        display: flex; flex-direction: column; padding: 16px 18px;
        background: var(--svc-surface); border: 1px solid var(--svc-border); border-radius: 14px;
        box-shadow: var(--svc-shadow); transition: border-color .2s, transform .2s, box-shadow .2s;
    }
    #servicos-wrapper .cat-card:hover { border-color: var(--svc-faint); transform: translateY(-2px); box-shadow: 0 14px 28px -20px rgba(15, 23, 42, .5); }
    #servicos-wrapper .cat-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
    #servicos-wrapper .cat-nome { display: flex; align-items: center; gap: 9px; color: var(--svc-text); font-size: 1.02rem; font-weight: 800; overflow-wrap: anywhere; }
    #servicos-wrapper .cat-ordem {
        flex-shrink: 0; min-width: 30px; height: 30px; padding: 0 8px; border-radius: 9px;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--svc-surface-3); color: var(--svc-muted); font-size: .8rem; font-weight: 800;
    }
    #servicos-wrapper .cat-metricas { display: flex; gap: 18px; margin: 14px 0 12px; }
    #servicos-wrapper .cat-metrica { display: flex; flex-direction: column; }
    #servicos-wrapper .cat-metrica b { color: var(--svc-text); font-size: 1.1rem; font-weight: 800; line-height: 1; }
    #servicos-wrapper .cat-metrica span { margin-top: 4px; color: var(--svc-faint); font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    #servicos-wrapper .cat-preview { display: flex; flex-wrap: wrap; gap: 4px; padding-top: 12px; border-top: 1px solid var(--svc-border-soft); }
    #servicos-wrapper .cat-preview { flex: 1; }
    #servicos-wrapper .cat-preview .badge { font-size: .72rem; }
    #servicos-wrapper .cat-vazia { color: var(--svc-faint); font-size: .8rem; font-style: italic; }
    /* margin-top:auto alinha as ações no rodapé mesmo com cards de alturas diferentes */
    #servicos-wrapper .cat-acoes { display: flex; justify-content: flex-end; gap: 6px; margin-top: auto; padding-top: 14px; }

    /* --- Estoque: cartões de resumo e barra de nível ----------------------- */
    #servicos-wrapper .estoque-resumo { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }
    #servicos-wrapper .est-card {
        padding: 14px 16px; background: var(--svc-surface); border: 1px solid var(--svc-border);
        border-radius: 12px; box-shadow: var(--svc-shadow);
    }
    #servicos-wrapper .est-card small { display: block; color: var(--svc-muted); font-size: .71rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    #servicos-wrapper .est-card strong { display: block; margin-top: 5px; color: var(--svc-text); font-size: 1.24rem; font-weight: 800; }
    #servicos-wrapper .est-card span { display: block; margin-top: 3px; color: var(--svc-faint); font-size: .73rem; font-weight: 600; }
    #servicos-wrapper .est-card.accent strong { color: #059669; }
    #servicos-wrapper .est-card.warn { border-color: #fcd34d; }
    #servicos-wrapper .est-card.warn strong { color: #b45309; }
    #servicos-wrapper .est-card.danger { border-color: #fca5a5; }
    #servicos-wrapper .est-card.danger strong { color: #dc2626; }

    #servicos-wrapper .est-nivel { min-width: 118px; }
    #servicos-wrapper .est-nivel-top { display: flex; align-items: baseline; gap: 5px; }
    #servicos-wrapper .est-nivel-qtd { color: var(--svc-text); font-size: 1.1rem; font-weight: 800; }
    #servicos-wrapper .est-nivel-un { color: var(--svc-faint); font-size: .76rem; font-weight: 600; }
    #servicos-wrapper .est-track { position: relative; height: 6px; margin-top: 7px; border-radius: 999px; background: var(--svc-surface-3); overflow: hidden; }
    #servicos-wrapper .est-fill { height: 100%; border-radius: 999px; background: #10b981; }
    #servicos-wrapper .est-fill.is-repor { background: #f59e0b; }
    #servicos-wrapper .est-fill.is-esgotado { background: #ef4444; }
    #servicos-wrapper .est-min { margin-top: 5px; color: var(--svc-faint); font-size: .71rem; font-weight: 600; }
    #servicos-wrapper tr.linha-alerta td:first-child { box-shadow: inset 3px 0 0 #f59e0b; }
    #servicos-wrapper tr.linha-critica td:first-child { box-shadow: inset 3px 0 0 #ef4444; }

    /* --- Histórico de estoque --------------------------------------------- */
    #servicos-wrapper .kardex-resumo { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    #servicos-wrapper .kardex-card {
        flex: 1 1 190px; display: flex; align-items: center; gap: 12px; padding: 13px 16px;
        background: var(--svc-surface); border: 1px solid var(--svc-border); border-radius: 12px; box-shadow: var(--svc-shadow);
    }
    #servicos-wrapper .kardex-card i { width: 38px; height: 38px; flex-shrink: 0; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; }
    #servicos-wrapper .kardex-card.k-in i { background: #ecfdf5; color: #059669; }
    #servicos-wrapper .kardex-card.k-out i { background: #fef2f2; color: #dc2626; }
    #servicos-wrapper .kardex-card.k-net i { background: #eff6ff; color: #2563eb; }
    html.dark-mode #servicos-wrapper .kardex-card i { background: var(--dm-surface-3); }
    #servicos-wrapper .kardex-card b { display: block; color: var(--svc-text); font-size: 1.15rem; font-weight: 800; line-height: 1.1; }
    #servicos-wrapper .kardex-card span { display: block; margin-top: 2px; color: var(--svc-muted); font-size: .75rem; font-weight: 600; }
    #servicos-wrapper .kardex-motivo { color: var(--svc-muted); font-size: .85rem; }
    #servicos-wrapper .kardex-data { color: var(--svc-muted); font-size: .86rem; }
    #servicos-wrapper .kardex-data small { display: block; margin-top: 2px; color: var(--svc-faint); font-size: .72rem; }

    #servicos-wrapper .estoque-toolbar .modern-select { width: auto; min-width: 190px; height: 42px; padding: 0 12px; background: var(--svc-surface); border: 1px solid var(--svc-border); border-radius: 9px; color: var(--svc-text); font: 600 .84rem 'Inter', sans-serif; cursor: pointer; }
    #servicos-wrapper .estoque-toolbar .btn-modern-filter { width: 42px; height: 42px; min-height: 42px; padding: 0; }

    /* --- Rodapé de resumo abaixo das tabelas ------------------------------- */
    #servicos-wrapper .svc-rodape { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 12px; padding: 0 4px; color: var(--svc-muted); font-size: .8rem; }
    #servicos-wrapper .svc-rodape b { color: var(--svc-text); font-weight: 800; }

    /* Estado vazio dentro de tabela: discreto, sem moldura */
    #servicos-wrapper .modern-table .empty-state { padding: 42px 20px; border: 0; background: none; color: var(--svc-muted); }
    #servicos-wrapper .modern-table .empty-state i { display: block; margin-bottom: 10px; color: var(--svc-faint); font-size: 2rem; }
    #servicos-wrapper .modern-table .empty-state small { display: block; margin-top: 5px; color: var(--svc-faint); font-size: .8rem; }
    #servicos-wrapper .cat-grid .empty-state { grid-column: 1 / -1; background: var(--svc-surface); border-color: var(--svc-border); color: var(--svc-muted); }

    @media (max-width: 768px) {
        #servicos-wrapper .btn-modern-add { justify-content: center; }
        #servicos-wrapper .action-buttons { justify-content: flex-end; }
        #servicos-wrapper .svc-search { flex-basis: 100%; }
        #servicos-wrapper .estoque-toolbar .modern-select { flex: 1 1 100%; min-width: 0; }
        #servicos-wrapper .estoque-toolbar .btn-modern-filter, #servicos-wrapper .estoque-toolbar .btn-clear-svc { width: 100%; }
        #servicos-wrapper .svc-toolbar select { flex: 1 1 100%; }
        #servicos-wrapper .svc-toolbar-count { margin-left: 0; flex-basis: 100%; }
        #servicos-wrapper .td-sub, #servicos-wrapper .badge-lista { max-width: none; }
        #servicos-wrapper tr.linha-alerta td:first-child,
        #servicos-wrapper tr.linha-critica td:first-child { box-shadow: none; }
    }
</style>

<div class="servicos-layout" id="servicos-wrapper">

    <?php if ($estoque_atencao > 0): ?>
    <div class="svc-alerta">
        <i class="fa fa-triangle-exclamation"></i>
        <div class="svc-alerta-txt">
            <?php if ($estoque_esgotados > 0): ?>
                <b><?= (int)$estoque_esgotados ?></b> produto(s) esgotado(s)<?= $estoque_a_repor > 0 ? ' e ' : '.' ?>
            <?php endif; ?>
            <?php if ($estoque_a_repor > 0): ?>
                <b><?= (int)$estoque_a_repor ?></b> no ponto de reposição.
            <?php endif; ?>
            Reponha antes que a venda de balcão pare.
        </div>
        <div class="svc-alerta-acoes">
            <?php if ($estoque_esgotados > 0): ?>
                <button type="button" class="svc-alerta-btn" data-ir-produtos="esgotado">Ver esgotados</button>
            <?php endif; ?>
            <?php if ($estoque_a_repor > 0): ?>
                <button type="button" class="svc-alerta-btn" data-ir-produtos="repor">Ver a repor</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="svc-overview">
        <button type="button" class="svc-stat sv-blue" data-ir-subtab="servicos-individuais">
            <i class="fa fa-cut"></i>
            <span class="svc-stat-body">
                <span class="svc-stat-value"><?= (int)$nServicos ?></span>
                <span class="svc-stat-label">Serviços · ticket médio <?= $moeda($servicoTicketMedio) ?></span>
            </span>
        </button>
        <button type="button" class="svc-stat sv-indigo" data-ir-subtab="combos">
            <i class="fa fa-layer-group"></i>
            <span class="svc-stat-body">
                <span class="svc-stat-value"><?= (int)$nCombos ?></span>
                <span class="svc-stat-label">Combos<?= $comboComDesconto > 0 ? ' · ' . number_format($comboEconomiaMedia, 0) . '% off médio' : '' ?></span>
            </span>
        </button>
        <button type="button" class="svc-stat sv-purple" data-ir-subtab="planos">
            <i class="fa fa-crown"></i>
            <span class="svc-stat-body">
                <span class="svc-stat-value"><?= $moeda($planoMrr) ?></span>
                <span class="svc-stat-label">Receita recorrente · <?= (int)$planoAssinantesTotal ?> assinante(s)</span>
            </span>
        </button>
        <button type="button" class="svc-stat sv-amber" data-ir-subtab="produtos">
            <i class="fa fa-box-open"></i>
            <span class="svc-stat-body">
                <span class="svc-stat-value"><?= $moeda($estoque_valor_venda) ?></span>
                <span class="svc-stat-label">Estoque a preço de venda · <?= (int)$estoque_unidades ?> un.</span>
            </span>
        </button>
        <button type="button" class="svc-stat <?= $estoque_atencao > 0 ? 'sv-amber' : 'sv-green' ?>" data-ir-produtos="<?= $estoque_esgotados > 0 ? 'esgotado' : ($estoque_a_repor > 0 ? 'repor' : 'todos') ?>">
            <i class="fa <?= $estoque_atencao > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            <span class="svc-stat-body">
                <span class="svc-stat-value"><?= (int)$estoque_atencao ?></span>
                <span class="svc-stat-label"><?= $estoque_atencao > 0 ? 'Produto(s) precisando de reposição' : 'Nenhum produto em falta' ?></span>
            </span>
        </button>
    </div>

    <div class="modern-sub-tabs">
        <button class="sub-tab-btn <?= $subAtivo('categorias') ?>" data-subtab="categorias"><i class="fa fa-tags"></i> Categorias <span class="st-count"><?= (int)$nCategorias ?></span></button>
        <button class="sub-tab-btn <?= $subAtivo('servicos-individuais') ?>" data-subtab="servicos-individuais"><i class="fa fa-cut"></i> Serviços <span class="st-count"><?= (int)$nServicos ?></span></button>
        <button class="sub-tab-btn <?= $subAtivo('combos') ?>" data-subtab="combos"><i class="fa fa-layer-group"></i> Combos <span class="st-count"><?= (int)$nCombos ?></span></button>
        <button class="sub-tab-btn <?= $subAtivo('planos') ?>" data-subtab="planos"><i class="fa fa-crown"></i> Assinaturas <span class="st-count"><?= (int)$nPlanos ?></span></button>
        <button class="sub-tab-btn <?= $subAtivo('produtos') ?>" data-subtab="produtos"><i class="fa fa-box-open"></i> Produtos &amp; Estoque <span class="st-count <?= $estoque_atencao > 0 ? 'is-alerta' : '' ?>"><?= $estoque_atencao > 0 ? (int)$estoque_atencao : (int)$nProdutos ?></span></button>
        <button class="sub-tab-btn <?= $subAtivo('historico-estoque') ?>" data-subtab="historico-estoque"><i class="fa fa-clipboard-list"></i> Histórico de Estoque <span class="st-count"><?= (int)$estoque_totalItems ?></span></button>
    </div>

    <!-- ================================ CATEGORIAS ============================ -->
    <div id="categorias" class="sub-tabcontent <?= $subAtivo('categorias') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-tags"></i> Gerenciar Categorias</h3>
                <p>Categorias organizam a vitrine de serviços na página de agendamento. Números menores aparecem primeiro para o cliente.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-categoria"><i class="fa fa-plus-circle"></i> Adicionar Categoria</button>
        </div>

        <?php if ($semCategoriaServicos > 0): ?>
        <div class="svc-alerta" style="margin-bottom:16px;">
            <i class="fa fa-circle-info"></i>
            <div class="svc-alerta-txt"><b><?= (int)$semCategoriaServicos ?></b> serviço(s) estão sem categoria e aparecem soltos na página de agendamento.</div>
            <div class="svc-alerta-acoes">
                <button type="button" class="svc-alerta-btn" data-ir-subtab="servicos-individuais">Revisar serviços</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="cat-grid">
            <?php if (empty($categoriasOrdenadas)): ?>
                <div class="empty-state">
                    <i class="fa fa-tags"></i>
                    Nenhuma categoria criada ainda.
                    <small>Crie categorias como "Cabelo", "Barba" ou "Tratamentos" para agrupar os serviços.</small>
                </div>
            <?php else: ?>
                <?php foreach ($categoriasOrdenadas as $cat):
                    $usoCat = $categoriaUso[$cat['id']] ?? [];
                    $servicosCat = $usoCat['servicos'] ?? [];
                    $combosCat   = $usoCat['combos'] ?? [];
                    $produtosCat = $usoCat['produtos'] ?? [];
                ?>
                <div class="cat-card">
                    <div class="cat-top">
                        <span class="cat-nome"><i class="fa fa-tag" style="color:var(--secondary-color, #0363c9); font-size:.9rem;"></i> <?= htmlspecialchars($cat['nome']) ?></span>
                        <span class="cat-ordem" title="Ordem de exibição">#<?= htmlspecialchars((string)($cat['ordem'] ?? '10')) ?></span>
                    </div>
                    <div class="cat-metricas">
                        <div class="cat-metrica"><b><?= count($servicosCat) ?></b><span>Serviços</span></div>
                        <div class="cat-metrica"><b><?= count($combosCat) ?></b><span>Combos</span></div>
                        <div class="cat-metrica"><b><?= count($produtosCat) ?></b><span>Produtos</span></div>
                    </div>
                    <div class="cat-preview">
                        <?php if (empty($servicosCat) && empty($combosCat)): ?>
                            <span class="cat-vazia">Nenhum serviço ou combo usa esta categoria.</span>
                        <?php else:
                            $previewCat = array_merge($servicosCat, $combosCat);
                            $extraCat = count($previewCat) - 4;
                            foreach (array_slice($previewCat, 0, 4) as $nomePrev): ?>
                                <span class="badge badge-gray"><?= htmlspecialchars($nomePrev) ?></span>
                            <?php endforeach; ?>
                            <?php if ($extraCat > 0): ?><span class="badge badge-blue">+<?= $extraCat ?></span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="cat-acoes">
                        <button class="btn-action btn-dark" data-modal-target="#modal-categoria" data-id="<?= $cat['id'] ?>" data-type="categoria" title="Editar categoria"><i class="fa fa-edit"></i></button>
                        <a href="?action=excluir_categoria&id=<?= $cat['id'] ?>&tab=servicos&subtab=categorias&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" title="Excluir categoria" onclick="return confirm('Atenção: Excluir esta categoria irá desassociá-la de todos os serviços. Deseja continuar?')"><i class="fa fa-trash"></i></a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================ SERVIÇOS ============================== -->
    <div id="servicos-individuais" class="sub-tabcontent <?= $subAtivo('servicos-individuais') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-cut"></i> Gerenciar Serviços</h3>
                <p>Cadastre os serviços avulsos, defina preços e a duração em slots de 30 minutos usada pela agenda.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-servico"><i class="fa fa-plus-circle"></i> Adicionar Serviço</button>
        </div>

        <?php if (!empty($servicosArr)): ?>
        <div class="svc-toolbar">
            <div class="svc-search">
                <i class="fa fa-search"></i>
                <input type="text" id="servico-busca" placeholder="Buscar serviço por nome ou descrição...">
            </div>
            <select id="servico-categoria">
                <option value="">Todas as categorias</option>
                <?php foreach ($categoriasOrdenadas as $catOpt): ?>
                    <option value="<?= htmlspecialchars($catOpt['id']) ?>"><?= htmlspecialchars($catOpt['nome']) ?></option>
                <?php endforeach; ?>
                <option value="__sem__">Sem categoria</option>
            </select>
            <select id="servico-ordem">
                <option value="nome">Ordenar: A → Z</option>
                <option value="valor_desc">Maior preço</option>
                <option value="valor_asc">Menor preço</option>
                <option value="pop_desc">Mais realizados</option>
                <option value="receita_desc">Maior receita</option>
            </select>
            <span class="svc-toolbar-count" id="servico-contagem"></span>
        </div>
        <?php endif; ?>

        <div class="modern-table-wrapper">
            <table class="modern-table" id="tabela-servicos">
                <thead>
                    <tr><th>Serviço</th><th>Valor</th><th>Duração</th><th>Categoria</th><th>Popularidade</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($servicosArr)): ?>
                        <tr><td colspan="6" class="empty-state">
                            <i class="fa fa-cut"></i>
                            Nenhum serviço cadastrado.
                            <small>Sem serviços, o cliente não consegue concluir um agendamento.</small>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($servicosArr as $s):
                            $stat = $servicoStats[$s['id']] ?? ['qtd' => 0, 'receita' => 0];
                            $slots = (int)($s['slots'] ?? 1); if ($slots <= 0) $slots = 1;
                            $descServ = trim((string)($s['descricao'] ?? ''));
                            $catServId = $s['categoria_id'] ?? '';
                            $catServNome = $categoriasArr[$catServId]['nome'] ?? '';
                            $popPct = $servicoPicoQtd > 0 ? ((int)$stat['qtd'] / $servicoPicoQtd) * 100 : 0;
                        ?>
                        <tr class="servico-row"
                            data-nome="<?= htmlspecialchars(mb_strtolower(($s['nome'] ?? '') . ' ' . $descServ)) ?>"
                            data-categoria="<?= htmlspecialchars($catServNome !== '' ? $catServId : '__sem__') ?>"
                            data-valor="<?= htmlspecialchars((string)(float)$s['valor']) ?>"
                            data-qtd="<?= (int)$stat['qtd'] ?>"
                            data-receita="<?= htmlspecialchars((string)(float)$stat['receita']) ?>"
                            data-ordem-nome="<?= htmlspecialchars(mb_strtolower($s['nome'] ?? '')) ?>">
                            <td data-label="Serviço">
                                <span class="td-nome"><?= htmlspecialchars($s['nome']) ?></span>
                                <?php if ($descServ !== ''): ?>
                                    <small class="td-sub"><?= htmlspecialchars(mb_strimwidth($descServ, 0, 110, '…')) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Valor" class="td-valor"><?= $moeda($s['valor']) ?></td>
                            <td data-label="Duração"><span class="badge badge-blue"><i class="fa fa-clock"></i> <?= $slots * 30 ?> min</span></td>
                            <td data-label="Categoria">
                                <?php if ($catServNome !== ''): ?>
                                    <span class="badge badge-gray"><?= htmlspecialchars($catServNome) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Sem categoria</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Popularidade">
                                <?php if ($stat['qtd'] > 0): ?>
                                    <div class="pop-cell">
                                        <div class="pop-top">
                                            <span class="pop-qtd"><?= (int)$stat['qtd'] ?>x</span>
                                            <span class="pop-receita"><?= $moeda($stat['receita']) ?></span>
                                        </div>
                                        <div class="pop-track"><div class="pop-fill" style="width: <?= max(4, min(100, round($popPct))) ?>%;"></div></div>
                                    </div>
                                <?php else: ?>
                                    <span class="pop-vazio">Ainda não realizado</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Ações">
                                <div class="action-buttons">
                                    <button class="btn-action btn-dark" data-modal-target="#modal-servico" data-id="<?= $s['id'] ?>" data-type="servico" title="Editar serviço"><i class="fa fa-edit"></i></button>
                                    <a href="?action=excluir_servico&id=<?= $s['id'] ?>&tab=servicos&subtab=servicos-individuais&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" title="Excluir serviço" onclick="return confirm('Excluir este serviço?')"><i class="fa fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($servicosArr)): ?>
        <div class="svc-rodape">
            <span><b><?= (int)$totalServicosRealizados ?></b> atendimentos concluídos</span>
            <span>Receita gerada: <b><?= $moeda($servicoReceitaTotal) ?></b></span>
            <span>Ticket médio da tabela: <b><?= $moeda($servicoTicketMedio) ?></b></span>
            <?php if ($servicoSemUso > 0): ?><span><b><?= (int)$servicoSemUso ?></b> serviço(s) nunca realizados</span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================= COMBOS =============================== -->
    <div id="combos" class="sub-tabcontent <?= $subAtivo('combos') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-layer-group"></i> Gerenciar Combos Promocionais</h3>
                <p>Agrupe 2 ou mais serviços por um preço promocional. A economia mostrada é o que o cliente deixa de pagar em relação aos avulsos.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-combo"><i class="fa fa-plus-circle"></i> Criar Novo Combo</button>
        </div>

        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr><th>Combo</th><th>Preço</th><th>Economia</th><th>Serviços inclusos</th><th>Categoria</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php if (empty($combosArr)): ?>
                    <tr><td colspan="6" class="empty-state">
                        <i class="fa fa-layer-group"></i>
                        Nenhum combo criado.
                        <small>Combos aumentam o ticket médio ao juntar serviços que costumam ser vendidos separados.</small>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($combosArr as $cid => $c):
                        $info = $comboInfo[$cid] ?? ['servicos' => [], 'cheio' => 0, 'economia' => 0, 'economia_pct' => 0, 'slots' => 0];
                    ?>
                    <tr>
                        <td data-label="Combo">
                            <span class="td-nome"><?= htmlspecialchars($c['nome']) ?></span>
                            <?php if ($info['slots'] > 0): ?>
                                <small class="td-sub"><i class="fa fa-clock"></i> <?= $info['slots'] * 30 ?> min · <?= count($info['servicos']) ?> serviço(s)</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Preço">
                            <span class="td-valor"><?= $moeda($c['valor']) ?></span>
                            <?php if ($info['cheio'] > 0 && $info['economia'] > 0.001): ?>
                                <small class="td-sub"><span class="td-riscado"><?= $moeda($info['cheio']) ?></span> avulso</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Economia">
                            <?php if ($info['economia'] > 0.001): ?>
                                <span class="badge badge-green"><i class="fa fa-arrow-down"></i> <?= number_format($info['economia_pct'], 0) ?>%</span>
                                <small class="td-sub"><?= $moeda($info['economia']) ?> de desconto</small>
                            <?php elseif ($info['economia'] < -0.001): ?>
                                <span class="badge badge-red">Acima do avulso</span>
                            <?php else: ?>
                                <span class="badge badge-gray">Sem desconto</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Serviços">
                            <div class="badge-lista">
                                <?php foreach ($info['servicos'] as $serv): ?>
                                    <span class="badge badge-gray"><?= htmlspecialchars($serv['nome']) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($info['servicos'])): ?>
                                    <span class="badge badge-warning">Nenhum serviço válido</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Categoria">
                            <span class="badge badge-gray"><?= htmlspecialchars($categoriasArr[$c['categoria_id']]['nome'] ?? 'Sem Categoria') ?></span>
                        </td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <button class="btn-action btn-dark" data-modal-target="#modal-combo" data-id="<?= $c['id'] ?>" data-type="combo" title="Editar combo"><i class="fa fa-edit"></i></button>
                                <a href="?action=excluir_combo&id=<?= $c['id'] ?>&tab=servicos&subtab=combos&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" title="Excluir combo" onclick="return confirm('Excluir este combo?')"><i class="fa fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($combosArr)): ?>
        <div class="svc-rodape">
            <span><b><?= (int)$comboComDesconto ?></b> de <?= (int)$nCombos ?> combo(s) com desconto real</span>
            <?php if ($comboComDesconto > 0): ?><span>Desconto médio: <b><?= number_format($comboEconomiaMedia, 1, ',', '.') ?>%</b></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================= PLANOS =============================== -->
    <div id="planos" class="sub-tabcontent <?= $subAtivo('planos') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-crown"></i> Planos: Barbearia por Assinatura</h3>
                <p>O cliente paga um valor fixo mensal automaticamente e os serviços inclusos saem com 100% de desconto.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-plano" data-type="novo_plano"><i class="fa fa-plus-circle"></i> Criar Novo Plano</button>
        </div>

        <?php if (!empty($planosArr)): ?>
        <div class="estoque-resumo">
            <div class="est-card accent"><small>Receita recorrente (MRR)</small><strong><?= $moeda($planoMrr) ?></strong><span>Somando todos os planos ativos</span></div>
            <div class="est-card"><small>Assinantes ativos</small><strong><?= (int)$planoAssinantesTotal ?></strong><span>Clientes com plano vigente</span></div>
            <div class="est-card"><small>Planos publicados</small><strong><?= (int)$nPlanos ?></strong><span>Ofertas disponíveis ao cliente</span></div>
            <div class="est-card"><small>Ticket médio da assinatura</small><strong><?= $moeda($planoAssinantesTotal > 0 ? $planoMrr / $planoAssinantesTotal : 0) ?></strong><span>MRR ÷ assinantes</span></div>
        </div>
        <?php endif; ?>

        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead><tr><th>Plano</th><th>Valor mensal</th><th>Serviços inclusos (ilimitados)</th><th>Assinantes</th><th>Receita do plano</th><th>Ações</th></tr></thead>
                <tbody>
                <?php if (empty($planosArr)): ?>
                    <tr><td colspan="6" class="empty-state">
                        <i class="fa fa-crown"></i>
                        Nenhum plano de assinatura criado.
                        <small>Assinaturas transformam receita avulsa em receita previsível todo mês.</small>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($planosArr as $p):
                        $nAssinantes = (int)($assinantesPorPlano[$p['id']] ?? 0);
                        $receitaRecorrente = $nAssinantes * (float)$p['valor'];
                        $servicosPlano = [];
                        $valorServicosPlano = 0;
                        foreach (explode(',', (string)($p['servicos_ids'] ?? '')) as $sidPl) {
                            $sidPl = trim($sidPl);
                            if ($sidPl === '' || !isset($servicosArr[$sidPl])) continue;
                            $servicosPlano[] = $servicosArr[$sidPl];
                            $valorServicosPlano += (float)$servicosArr[$sidPl]['valor'];
                        }
                    ?>
                    <tr>
                        <td data-label="Plano">
                            <span class="td-nome" style="color:#7c3aed;"><i class="fa fa-crown"></i> <?= htmlspecialchars($p['nome']) ?></span>
                            <?php if ($valorServicosPlano > 0): ?>
                                <small class="td-sub">Equivale a <?= $moeda($valorServicosPlano) ?> em serviços avulsos por visita.</small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Valor mensal" class="td-valor-vip"><?= $moeda($p['valor']) ?></td>
                        <td data-label="Serviços">
                            <div class="badge-lista">
                                <?php foreach ($servicosPlano as $servPl): ?>
                                    <span class="badge badge-purple"><?= htmlspecialchars($servPl['nome']) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($servicosPlano)): ?>
                                    <span class="badge badge-warning">Nenhum serviço incluso</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Assinantes">
                            <?php if ($nAssinantes > 0): ?>
                                <span class="pop-qtd"><i class="fa fa-users" style="color:#7c3aed;"></i> <?= $nAssinantes ?></span>
                            <?php else: ?>
                                <span class="pop-vazio">Sem assinantes</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Receita do plano">
                            <?php if ($receitaRecorrente > 0): ?>
                                <span class="td-valor"><?= $moeda($receitaRecorrente) ?></span>
                                <small class="td-sub">por mês</small>
                            <?php else: ?>
                                <span style="color:var(--svc-faint);">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <button class="btn-action btn-dark" data-modal-target="#modal-plano" data-id="<?= $p['id'] ?>" data-type="plano" data-nome="<?= htmlspecialchars($p['nome']) ?>" data-valor="<?= htmlspecialchars($p['valor']) ?>" data-servicos="<?= htmlspecialchars($p['servicos_ids']) ?>" title="Editar plano"><i class="fa fa-edit"></i></button>
                                <a href="?action=excluir_plano&id=<?= $p['id'] ?>&tab=servicos&subtab=planos&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" title="Excluir plano" onclick="return confirm('Excluir este plano?')"><i class="fa fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================ PRODUTOS & ESTOQUE ========================= -->
    <div id="produtos" class="sub-tabcontent <?= $subAtivo('produtos') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-box-open"></i> Controle de Estoque (Produtos)</h3>
                <p>Cadastre produtos, acompanhe custo, margem e nível de estoque. Cada entrada ou saída fica registrada no histórico.</p>
            </div>
            <button class="btn-modern-add" data-modal-target="#modal-produto" style="background-color: #f59e0b;"><i class="fa fa-plus-circle"></i> Cadastrar Produto</button>
        </div>

        <?php if (!empty($produtosArr)): ?>
        <div class="estoque-resumo">
            <div class="est-card"><small>Valor em estoque (custo)</small><strong><?= $moeda($estoque_valor_custo) ?></strong><span><?= (int)$estoque_unidades ?> unidades em prateleira</span></div>
            <div class="est-card"><small>Valor em estoque (venda)</small><strong><?= $moeda($estoque_valor_venda) ?></strong><span>Se tudo for vendido a preço cheio</span></div>
            <div class="est-card accent"><small>Lucro potencial</small><strong><?= $moeda($estoque_lucro_potencial) ?></strong><span><?= $estoque_sem_custo > 0 ? (int)$estoque_sem_custo . ' produto(s) sem custo informado' : 'Todos os custos preenchidos' ?></span></div>
            <div class="est-card <?= $estoque_a_repor > 0 ? 'warn' : '' ?>"><small>A repor</small><strong><?= (int)$estoque_a_repor ?></strong><span>No ponto de reposição</span></div>
            <div class="est-card <?= $estoque_esgotados > 0 ? 'danger' : '' ?>"><small>Esgotados</small><strong><?= (int)$estoque_esgotados ?></strong><span>Sem unidades disponíveis</span></div>
        </div>

        <div class="svc-toolbar">
            <div class="svc-search">
                <i class="fa fa-search"></i>
                <input type="text" id="produto-busca" placeholder="Buscar produto por nome...">
            </div>
            <div class="svc-chips">
                <button type="button" class="svc-chip active" data-filtro="todos">Todos <b><?= (int)$nProdutos ?></b></button>
                <button type="button" class="svc-chip chip-warn" data-filtro="repor">A repor <b><?= (int)$estoque_a_repor ?></b></button>
                <button type="button" class="svc-chip chip-danger" data-filtro="esgotado">Esgotados <b><?= (int)$estoque_esgotados ?></b></button>
                <button type="button" class="svc-chip" data-filtro="ok">Disponíveis <b><?= (int)$estoque_ok ?></b></button>
            </div>
            <select id="produto-ordem">
                <option value="urgencia">Ordenar: urgência</option>
                <option value="nome">A → Z</option>
                <option value="estoque_asc">Menor estoque</option>
                <option value="valor_desc">Maior preço</option>
                <option value="margem_desc">Maior margem</option>
            </select>
            <span class="svc-toolbar-count" id="produto-contagem"></span>
        </div>
        <?php endif; ?>

        <div class="modern-table-wrapper">
            <table class="modern-table" id="tabela-produtos">
                <thead>
                    <tr><th>Produto</th><th>Custo / Venda</th><th>Margem</th><th>Nível de estoque</th><th>Valor parado</th><th>Status</th><th>Ações</th></tr>
                </thead>
                <tbody>
                <?php if (empty($produtosArr)): ?>
                    <tr><td colspan="7" class="empty-state">
                        <i class="fa fa-box-open"></i>
                        Nenhum produto cadastrado no estoque.
                        <small>Cadastre pomadas, shampoos e acessórios para vendê-los direto na comanda.</small>
                    </td></tr>
                <?php else: ?>
                    <?php $ordemUrgencia = 0; foreach ($produtosOrdenados as $prod):
                        $qtd = (int)$prod['quantidade'];
                        $minimo = (int)($prod['estoque_minimo'] ?? 5);
                        $custoProd = (float)($prod['custo'] ?? 0);
                        $vendaProd = (float)$prod['valor'];
                        $margemProd = $vendaProd - $custoProd;
                        $margemPct = $vendaProd > 0 ? ($margemProd / $vendaProd) * 100 : 0;
                        $statusEstoque = $qtd <= 0 ? 'esgotado' : ($qtd <= $minimo ? 'repor' : 'ok');
                        // Barra cheia = duas vezes o ponto de reposição (ou o próprio
                        // estoque, quando ele já passou disso).
                        $referencia = max(1, $minimo * 2, $qtd);
                        $nivelPct = max(0, min(100, ($qtd / $referencia) * 100));
                        $classeLinha = $statusEstoque === 'esgotado' ? 'linha-critica' : ($statusEstoque === 'repor' ? 'linha-alerta' : '');
                        $catProdNome = $categoriasArr[$prod['categoria_id'] ?? '']['nome'] ?? '';
                    ?>
                    <tr class="produto-row <?= $classeLinha ?>"
                        data-nome="<?= htmlspecialchars(mb_strtolower($prod['nome'])) ?>"
                        data-status="<?= $statusEstoque ?>"
                        data-urgencia="<?= $ordemUrgencia++ ?>"
                        data-qtd="<?= $qtd ?>"
                        data-valor="<?= htmlspecialchars((string)$vendaProd) ?>"
                        data-margem="<?= htmlspecialchars((string)$margemProd) ?>">
                        <td data-label="Produto">
                            <span class="td-nome"><?= htmlspecialchars($prod['nome']) ?></span>
                            <?php if ($catProdNome !== ''): ?><small class="td-sub"><i class="fa fa-tag"></i> <?= htmlspecialchars($catProdNome) ?></small><?php endif; ?>
                        </td>
                        <td data-label="Custo / Venda">
                            <?php if ($custoProd > 0): ?>
                                <span style="color:var(--svc-muted);"><?= $moeda($custoProd) ?></span>
                            <?php else: ?>
                                <span style="color:var(--svc-faint);">custo —</span>
                            <?php endif; ?>
                            <small class="td-sub" style="color:var(--svc-text); font-weight:800; font-size:.9rem;"><?= $moeda($vendaProd) ?></small>
                        </td>
                        <td data-label="Margem">
                            <?php if ($custoProd > 0): ?>
                                <strong style="color:<?= $margemProd >= 0 ? '#059669' : '#dc2626' ?>;"><?= $moeda($margemProd) ?></strong>
                                <small class="td-sub"><?= number_format($margemPct, 0) ?>% do preço de venda</small>
                            <?php else: ?>
                                <span class="badge badge-warning">Informe o custo</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Nível de estoque">
                            <div class="est-nivel">
                                <div class="est-nivel-top"><span class="est-nivel-qtd"><?= $qtd ?></span><span class="est-nivel-un">un.</span></div>
                                <div class="est-track"><div class="est-fill <?= $statusEstoque === 'esgotado' ? 'is-esgotado' : ($statusEstoque === 'repor' ? 'is-repor' : '') ?>" style="width: <?= round($nivelPct) ?>%;"></div></div>
                                <div class="est-min">mínimo: <?= $minimo ?> un.</div>
                            </div>
                        </td>
                        <td data-label="Valor parado">
                            <strong style="color:var(--svc-text);"><?= $moeda($qtd * $vendaProd) ?></strong>
                            <?php if ($custoProd > 0): ?><small class="td-sub"><?= $moeda($qtd * $custoProd) ?> a custo</small><?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if ($statusEstoque === 'esgotado'): ?>
                                <span class="badge badge-red">Esgotado</span>
                            <?php elseif ($statusEstoque === 'repor'): ?>
                                <span class="badge badge-warning">Repor estoque</span>
                            <?php else: ?>
                                <span class="badge badge-green">Disponível</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ações">
                            <div class="action-buttons">
                                <button class="btn-action btn-blue" data-modal-target="#modal-movimentar-estoque" data-id="<?= $prod['id'] ?>" data-type="movimentar_estoque" data-nome="<?= htmlspecialchars($prod['nome']) ?>" title="Movimentar Estoque (Entrada/Saída)"><i class="fa fa-exchange-alt"></i></button>
                                <button class="btn-action btn-dark" data-modal-target="#modal-produto" data-id="<?= $prod['id'] ?>" data-type="produto" data-nome="<?= htmlspecialchars($prod['nome']) ?>" data-valor="<?= htmlspecialchars($prod['valor']) ?>" data-quantidade="<?= $qtd ?>" data-estoque-minimo="<?= $minimo ?>" data-custo="<?= htmlspecialchars((string)$custoProd) ?>" data-categoria="<?= htmlspecialchars($prod['categoria_id'] ?? '') ?>" title="Editar Dados Base do Produto"><i class="fa fa-edit"></i></button>
                                <a href="?action=excluir_produto&id=<?= $prod['id'] ?>&tab=servicos&subtab=produtos&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" onclick="return confirm('Excluir produto definitivamente? O histórico dele será mantido para auditoria.')" title="Excluir Produto"><i class="fa fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- =========================== HISTÓRICO DE ESTOQUE ======================== -->
    <div id="historico-estoque" class="sub-tabcontent <?= $subAtivo('historico-estoque') ?>">
        <div class="section-header-bar">
            <div class="section-header-info">
                <h3><i class="fa fa-clipboard-list"></i> Histórico de Movimentações (Kardex)</h3>
                <p>Registro completo de todas as entradas e saídas de produtos, com autor e motivo, para auditoria.</p>
            </div>
        </div>

        <div class="kardex-resumo">
            <div class="kardex-card k-in">
                <i class="fa fa-plus"></i>
                <div><b>+<?= (int)$estoque_un_entradas ?> un.</b><span><?= (int)$estoque_mov_entradas ?> entrada(s)</span></div>
            </div>
            <div class="kardex-card k-out">
                <i class="fa fa-minus"></i>
                <div><b>−<?= (int)$estoque_un_saidas ?> un.</b><span><?= (int)$estoque_mov_saidas ?> saída(s)</span></div>
            </div>
            <div class="kardex-card k-net">
                <i class="fa fa-scale-balanced"></i>
                <div><b><?= ($estoque_un_entradas - $estoque_un_saidas) >= 0 ? '+' : '−' ?><?= abs($estoque_un_entradas - $estoque_un_saidas) ?> un.</b><span>Saldo do período listado</span></div>
            </div>
        </div>

        <form method="GET" action="admin.php" class="svc-toolbar estoque-toolbar">
            <input type="hidden" name="tab" value="servicos">
            <input type="hidden" name="subtab" value="historico-estoque">
            <div class="svc-search">
                <i class="fa fa-search"></i>
                <input type="search" name="busca_estoque"
                       value="<?= htmlspecialchars($busca_estoque) ?>"
                       placeholder="Buscar por produto, motivo ou usuário...">
            </div>
            <select name="filtro_tipo_estoque" class="modern-select">
                <option value="">Entradas e saídas</option>
                <option value="entrada" <?= $filtro_tipo_estoque === 'entrada' ? 'selected' : '' ?>>Somente entradas</option>
                <option value="saida" <?= $filtro_tipo_estoque === 'saida' ? 'selected' : '' ?>>Somente saídas</option>
            </select>
            <button type="submit" class="btn-modern-filter" title="Aplicar filtros"><i class="fa fa-search"></i></button>
            <?php if ($estoque_filtros_ativos): ?>
                <a href="admin.php?tab=servicos&subtab=historico-estoque" class="btn-clear-svc" title="Limpar filtros"><i class="fa fa-times"></i></a>
            <?php endif; ?>
        </form>

        <div class="svc-rodape" style="margin: 0 0 10px;">
            <span><b><?= $estoque_totalItems ?></b> movimentação(ões)<?= $estoque_filtros_ativos ? ' encontrada(s)' : ' registrada(s)' ?><?php if ($estoque_totalPages > 1): ?> · página <?= $estoque_currentPage ?> de <?= $estoque_totalPages ?><?php endif; ?></span>
        </div>

        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr><th>Data / Hora</th><th>Produto</th><th>Tipo</th><th>Qtd</th><th>Motivo</th><th>Usuário</th></tr>
                </thead>
                <tbody>
                <?php if (empty($estoqueLogsArr)): ?>
                    <tr><td colspan="6" class="empty-state">
                        <i class="fa fa-clipboard-list"></i>
                        <?= $estoque_filtros_ativos ? 'Nenhuma movimentação corresponde aos filtros aplicados.' : 'Nenhuma movimentação registrada até o momento.' ?>
                        <?php if (!$estoque_filtros_ativos): ?><small>Toda entrada ou saída feita em "Produtos &amp; Estoque" aparece aqui.</small><?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($estoqueLogsArr as $log):
                        $ehEntrada = ($log['tipo'] ?? '') === 'entrada';
                        $tsLog = strtotime($log['data_hora'] ?? '');
                    ?>
                    <tr>
                        <td data-label="Data / Hora" class="kardex-data">
                            <?= $tsLog ? date('d/m/Y', $tsLog) : '—' ?>
                            <small><?= $tsLog ? date('H:i', $tsLog) : '' ?></small>
                        </td>
                        <td data-label="Produto" class="td-nome"><?= htmlspecialchars($log['produto_nome'] ?? 'Produto Excluído') ?></td>
                        <td data-label="Tipo">
                            <?php if ($ehEntrada): ?>
                                <span class="badge badge-green"><i class="fa fa-plus"></i> Entrada</span>
                            <?php else: ?>
                                <span class="badge badge-red"><i class="fa fa-minus"></i> Saída</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Quantidade">
                            <strong style="color:<?= $ehEntrada ? '#059669' : '#dc2626' ?>;"><?= $ehEntrada ? '+' : '−' ?><?= (int)$log['quantidade'] ?></strong>
                            <span style="color:var(--svc-faint); font-size:.8rem;">un.</span>
                        </td>
                        <td data-label="Motivo" class="kardex-motivo"><?= htmlspecialchars($log['motivo'] ?? '') ?></td>
                        <td data-label="Usuário"><span class="badge badge-gray"><i class="fa fa-user"></i> <?= htmlspecialchars($log['usuario'] ?? '') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($estoque_totalPages > 1): ?>
            <div class="modern-pagination">
                <?php
                $estoque_janela_ini = max(1, $estoque_currentPage - 3);
                $estoque_janela_fim = min($estoque_totalPages, $estoque_janela_ini + 6);
                $estoque_base = ['tab' => 'servicos', 'subtab' => 'historico-estoque', 'busca_estoque' => $busca_estoque, 'filtro_tipo_estoque' => $filtro_tipo_estoque];
                if ($estoque_currentPage > 1): ?>
                    <a href="?<?= http_build_query($estoque_base + ['est_page' => $estoque_currentPage - 1]) ?>" title="Anterior"><i class="fa fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($i = $estoque_janela_ini; $i <= $estoque_janela_fim; $i++): ?>
                    <a href="?<?= http_build_query($estoque_base + ['est_page' => $i]) ?>" class="<?= $i === $estoque_currentPage ? 'current' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($estoque_currentPage < $estoque_totalPages): ?>
                    <a href="?<?= http_build_query($estoque_base + ['est_page' => $estoque_currentPage + 1]) ?>" title="Próxima"><i class="fa fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const wrapper = document.getElementById('servicos-wrapper');
    if (!wrapper) return;

    // ---------------------------------------------------------------- sub-abas
    const subTabBtns = wrapper.querySelectorAll('.modern-sub-tabs .sub-tab-btn');

    function abrirSubtab(targetId) {
        let encontrou = false;
        subTabBtns.forEach(function (b) {
            const ativo = b.dataset.subtab === targetId;
            b.classList.toggle('active', ativo);
            if (ativo) encontrou = true;
        });
        if (!encontrou) return;
        wrapper.querySelectorAll('.sub-tabcontent').forEach(function (content) {
            content.classList.toggle('active', content.id === targetId);
        });
        const url = new URL(window.location);
        url.searchParams.set('subtab', targetId);
        window.history.pushState({}, '', url);
    }

    subTabBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            abrirSubtab(btn.dataset.subtab);
        });
    });
    // A sub-aba correta já vem marcada como "active" pelo servidor (via ?subtab=),
    // então não simulamos clique aqui — é isso que evita o "piscar" na primeira
    // sub-aba antes de trocar para a sub-aba salva.

    // Atalhos da visão geral / dos avisos de estoque.
    wrapper.addEventListener('click', function (e) {
        const atalhoSub = e.target.closest('[data-ir-subtab]');
        if (atalhoSub) { abrirSubtab(atalhoSub.dataset.irSubtab); return; }

        const atalhoProd = e.target.closest('[data-ir-produtos]');
        if (atalhoProd) {
            abrirSubtab('produtos');
            // Limpa a busca antes de aplicar o filtro: senão "Ver esgotados"
            // devolveria uma lista vazia se houvesse um termo digitado antes.
            const busca = document.getElementById('produto-busca');
            if (busca) busca.value = '';
            const chip = wrapper.querySelector('.svc-chip[data-filtro="' + atalhoProd.dataset.irProdutos + '"]');
            if (chip) chip.click();
            const alvo = document.getElementById('tabela-produtos');
            if (alvo && alvo.scrollIntoView) alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // ------------------------------------------------- filtro/ordenação genéricos
    // Reordena as linhas visíveis e mostra um estado vazio próprio quando nada
    // sobra — as duas tabelas filtráveis (serviços e produtos) usam o mesmo motor.
    function criarFiltro(config) {
        const tabela = document.getElementById(config.tabelaId);
        if (!tabela) return null;
        const tbody = tabela.querySelector('tbody');
        const linhas = Array.from(tabela.querySelectorAll('tbody tr.' + config.linhaClasse));
        if (!linhas.length) return null;
        const contador = config.contadorId ? document.getElementById(config.contadorId) : null;

        function aplicar() {
            let visiveis = 0;
            linhas.forEach(function (row) {
                const mostra = config.combina(row);
                row.style.display = mostra ? '' : 'none';
                if (mostra) visiveis++;
            });

            const criterio = config.criterioOrdem ? config.criterioOrdem() : null;
            if (criterio) {
                linhas.slice().sort(criterio).forEach(function (row) { tbody.appendChild(row); });
            }

            let vazio = tbody.querySelector('.' + config.linhaClasse + '-vazio');
            if (visiveis === 0) {
                if (!vazio) {
                    vazio = document.createElement('tr');
                    vazio.className = config.linhaClasse + '-vazio';
                    vazio.innerHTML = '<td colspan="' + config.colspan + '" class="empty-state"><i class="fa fa-filter-circle-xmark"></i>' + config.textoVazio + '</td>';
                }
                tbody.appendChild(vazio);
            } else if (vazio) {
                vazio.remove();
            }

            if (contador) {
                contador.innerHTML = visiveis === linhas.length
                    ? '<strong>' + linhas.length + '</strong> ' + config.rotulo
                    : '<strong>' + visiveis + '</strong> de ' + linhas.length + ' ' + config.rotulo;
            }
        }

        aplicar();
        return aplicar;
    }

    function num(row, campo) { return parseFloat(row.dataset[campo] || '0') || 0; }
    function txt(row, campo) { return row.dataset[campo] || ''; }

    // ------------------------------------------------------------------ serviços
    const servBusca = document.getElementById('servico-busca');
    const servCategoria = document.getElementById('servico-categoria');
    const servOrdem = document.getElementById('servico-ordem');

    const aplicarServicos = criarFiltro({
        tabelaId: 'tabela-servicos',
        linhaClasse: 'servico-row',
        colspan: 6,
        contadorId: 'servico-contagem',
        rotulo: 'serviço(s)',
        textoVazio: 'Nenhum serviço encontrado com os filtros aplicados.',
        combina: function (row) {
            const termo = (servBusca ? servBusca.value : '').trim().toLowerCase();
            const cat = servCategoria ? servCategoria.value : '';
            const casaBusca = termo === '' || txt(row, 'nome').indexOf(termo) !== -1;
            const casaCat = cat === '' || txt(row, 'categoria') === cat;
            return casaBusca && casaCat;
        },
        criterioOrdem: function () {
            const modo = servOrdem ? servOrdem.value : 'nome';
            if (modo === 'valor_desc') return function (a, b) { return num(b, 'valor') - num(a, 'valor'); };
            if (modo === 'valor_asc') return function (a, b) { return num(a, 'valor') - num(b, 'valor'); };
            if (modo === 'pop_desc') return function (a, b) { return num(b, 'qtd') - num(a, 'qtd'); };
            if (modo === 'receita_desc') return function (a, b) { return num(b, 'receita') - num(a, 'receita'); };
            return function (a, b) { return txt(a, 'ordemNome').localeCompare(txt(b, 'ordemNome'), 'pt-BR'); };
        }
    });

    if (aplicarServicos) {
        if (servBusca) servBusca.addEventListener('input', aplicarServicos);
        if (servCategoria) servCategoria.addEventListener('change', aplicarServicos);
        if (servOrdem) servOrdem.addEventListener('change', aplicarServicos);
    }

    // ------------------------------------------------------------------ produtos
    const prodBusca = document.getElementById('produto-busca');
    const prodOrdem = document.getElementById('produto-ordem');
    const prodChips = wrapper.querySelectorAll('.svc-chip[data-filtro]');
    let filtroProduto = 'todos';

    const aplicarProdutos = criarFiltro({
        tabelaId: 'tabela-produtos',
        linhaClasse: 'produto-row',
        colspan: 7,
        contadorId: 'produto-contagem',
        rotulo: 'produto(s)',
        textoVazio: 'Nenhum produto encontrado com os filtros aplicados.',
        combina: function (row) {
            const termo = (prodBusca ? prodBusca.value : '').trim().toLowerCase();
            const casaBusca = termo === '' || txt(row, 'nome').indexOf(termo) !== -1;
            const casaFiltro = filtroProduto === 'todos' || txt(row, 'status') === filtroProduto;
            return casaBusca && casaFiltro;
        },
        criterioOrdem: function () {
            const modo = prodOrdem ? prodOrdem.value : 'urgencia';
            if (modo === 'nome') return function (a, b) { return txt(a, 'nome').localeCompare(txt(b, 'nome'), 'pt-BR'); };
            if (modo === 'estoque_asc') return function (a, b) { return num(a, 'qtd') - num(b, 'qtd'); };
            if (modo === 'valor_desc') return function (a, b) { return num(b, 'valor') - num(a, 'valor'); };
            if (modo === 'margem_desc') return function (a, b) { return num(b, 'margem') - num(a, 'margem'); };
            return function (a, b) { return num(a, 'urgencia') - num(b, 'urgencia'); };
        }
    });

    if (aplicarProdutos) {
        if (prodBusca) prodBusca.addEventListener('input', aplicarProdutos);
        if (prodOrdem) prodOrdem.addEventListener('change', aplicarProdutos);
        prodChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                prodChips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                filtroProduto = chip.dataset.filtro || 'todos';
                aplicarProdutos();
            });
        });
    }
});
</script>

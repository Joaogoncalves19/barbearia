<?php
// admin_tabs/relatorios.php
// Central de Relatórios — a tela, a impressão e a exportação CSV usam os mesmos
// motores de cálculo (preparados em admin_data.php / lib/relatorio_functions.php).

// Métricas de avaliação por profissional no período.
$avaliacoesPeriodoBarbeiro = [];
foreach ($avaliacoesFiltradas as $avaliacao) {
    $barbeiroId = (string)($avaliacao['barbeiro_id'] ?? '');
    if ($barbeiroId === '') {
        continue;
    }
    $avaliacoesPeriodoBarbeiro[$barbeiroId][] = (float)($avaliacao['rating'] ?? 0);
}
$totalAgendamentosPeriodo = (int)($contagemStatus['total'] ?? 0);
$taxaConclusaoPeriodo = $totalAgendamentosPeriodo > 0
    ? round(((int)($contagemStatus['concluido'] ?? 0) / $totalAgendamentosPeriodo) * 100, 1)
    : 0;

$mediaAvaliacoesPeriodo = 'N/A';
if (!empty($avaliacoesFiltradas)) {
    $somaNotas = array_sum(array_map(fn($a) => (float)($a['rating'] ?? 0), $avaliacoesFiltradas));
    $mediaAvaliacoesPeriodo = number_format($somaNotas / count($avaliacoesFiltradas), 1, ',', '.');
}

// Renderiza o "selo" de variação percentual vs. período anterior.
$fmtVar = function ($var) {
    if ($var === null) {
        return '<span class="rel-delta rel-delta-neutral" title="Sem base no período anterior"><i class="fa fa-sparkles"></i> novo</span>';
    }
    if ($var > 0) {
        return '<span class="rel-delta rel-delta-up"><i class="fa fa-arrow-trend-up"></i> ' . number_format($var, 1, ',', '.') . '%</span>';
    }
    if ($var < 0) {
        return '<span class="rel-delta rel-delta-down"><i class="fa fa-arrow-trend-down"></i> ' . number_format(abs($var), 1, ',', '.') . '%</span>';
    }
    return '<span class="rel-delta rel-delta-neutral"><i class="fa fa-minus"></i> 0%</span>';
};

// Botão de exportação CSV para um determinado relatório.
$csvBtn = function ($tipo) use ($data_inicio_filtro, $data_fim_filtro) {
    $url = 'exportar_relatorio.php?tipo=' . urlencode($tipo)
        . '&data_inicio=' . urlencode($data_inicio_filtro)
        . '&data_fim=' . urlencode($data_fim_filtro);
    return '<a class="btn-export-csv" href="' . $url . '"><i class="fa fa-file-csv"></i> Exportar CSV</a>';
};

$periodoAnteriorLabel = date('d/m/Y', strtotime($comparativoPeriodo['anterior_inicio']))
    . ' a ' . date('d/m/Y', strtotime($comparativoPeriodo['anterior_fim']));

// Produtos com estoque no/abaixo do ponto de reposição.
$produtosEstoqueBaixo = array_filter($produtosArr, function ($p) {
    $qtd = (int)($p['quantidade'] ?? 0);
    $min = (int)($p['estoque_minimo'] ?? 0);
    return $min > 0 && $qtd <= $min;
});
?>

<style>
    /* ==========================================================================
       ESTILOS PREMIUM PARA CENTRAL DE RELATÓRIOS
       ========================================================================== */
    .relatorios-layout { display: flex; flex-direction: column; gap: 25px; animation: fadeIn 0.5s ease-out; padding-bottom: 50px; }

    /* --- Menu de Navegação Interna --- */
    .report-nav { display: flex; gap: 12px; margin-bottom: 10px; overflow-x: auto; padding-bottom: 5px; }
    .report-nav-btn {
        padding: 12px 20px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px;
        color: #64748b; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: 0.2s;
        white-space: nowrap; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    .report-nav-btn:hover { background: #f8fafc; color: #1e293b; border-color: #cbd5e1; }
    .report-nav-btn.active { background: var(--secondary-color, #007bff); color: white; border-color: var(--secondary-color); box-shadow: 0 4px 10px rgba(0,123,255,0.2); }

    .report-section { display: none; }
    .report-section.active { display: block; }

    /* --- Cabeçalho e Filtros --- */
    .report-header-bar {
        display: flex; justify-content: space-between; align-items: center;
        background: #ffffff; padding: 20px 25px; border-radius: 10px;
        border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
        flex-wrap: wrap; gap: 20px;
    }
    .report-header-bar .title h3 { margin: 0 0 5px 0; font-size: 1.4rem; color: #1e293b; font-weight: 800; }
    .report-header-actions { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
    .report-period-form { display: grid !important; grid-template-columns: repeat(2, minmax(145px, 1fr)) auto; gap: 8px; align-items: end; }
    .report-filter-field { display: flex; flex-direction: column; gap: 5px; }
    .report-filter-field span { color: #64748b; font-size: .72rem; font-weight: 800; text-transform: uppercase; }
    .report-filter-field .modern-input { width: 100%; min-height: 42px; padding: 8px 10px !important; border-radius: 8px; }
    .report-period-form .btn-filtrar {
        min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        padding: 8px 15px !important; border: 1px solid var(--secondary-color, #007bff);
        border-radius: 8px; background: white; color: var(--secondary-color, #007bff);
        font: inherit; font-weight: 800; cursor: pointer;
    }
    .report-period-form .btn-filtrar:hover { background: color-mix(in srgb, var(--secondary-color, #007bff) 8%, white); }

    .report-quick-ranges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
    .report-quick-ranges button {
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; border-radius: 20px;
        padding: 5px 12px; font-size: .78rem; font-weight: 700; cursor: pointer; transition: .15s;
    }
    .report-quick-ranges button:hover { background: var(--secondary-color, #007bff); color: #fff; border-color: var(--secondary-color); }

    .btn-print-report {
        background: var(--secondary-color, #007bff); color: white; padding: 10px 20px; border-radius: 8px;
        text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: 0.2s;
    }
    .btn-print-report:hover { filter: brightness(0.9); transform: translateY(-1px); }

    /* --- Grid de KPIs --- */
    .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
    .report-kpi-card { background: #ffffff; padding: 22px; border-radius: 10px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 15px; transition: 0.3s; }
    .report-kpi-card .icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
    .report-kpi-card .data { min-width: 0; }
    .report-kpi-card .data h4 { margin: 0; font-size: 0.8rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .report-kpi-card .data .val { font-size: 1.5rem; font-weight: 800; color: #0f172a; }

    /* Selo de variação vs. período anterior */
    .rel-delta { display: inline-flex; align-items: center; gap: 4px; font-size: .74rem; font-weight: 800; padding: 2px 8px; border-radius: 20px; margin-top: 4px; }
    .rel-delta-up { background: #ecfdf5; color: #047857; }
    .rel-delta-down { background: #fef2f2; color: #b91c1c; }
    .rel-delta-neutral { background: #f1f5f9; color: #64748b; }
    .rel-delta-hint { display: block; margin-top: 3px; font-size: .68rem; color: #94a3b8; }

    /* --- Tabelas / Cards --- */
    .table-card { background: #ffffff; border-radius: 10px; border: 1px solid #e5e7eb; padding: 25px; margin-top: 25px; }
    .table-card-header { margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 800; color: #1e293b; font-size: 1.1rem; flex-wrap: wrap; }
    .table-card-header .tc-title { display: flex; align-items: center; gap: 10px; }
    .table-card-header .tc-actions { margin-left: auto; }
    .btn-export-csv {
        display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
        background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-radius: 8px;
        padding: 7px 13px; font-size: .82rem; font-weight: 800; transition: .15s;
    }
    .btn-export-csv:hover { background: #15803d; color: #fff; border-color: #15803d; }

    .rel-two-col { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 25px; }
    .rel-two-col .table-card { margin-top: 25px; }

    /* --- Gráficos dos relatórios --- */
    .rel-chart-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; margin-top: 25px; }
    .rel-chart-card { min-width: 0; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px 22px; }
    .rel-chart-card.rel-chart-wide { grid-column: 1 / -1; }
    .rel-chart-header { display: flex; align-items: center; gap: 9px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-size: .98rem; font-weight: 800; }
    .rel-chart-header i { color: var(--secondary-color, #007bff); }
    .rel-chart-box { position: relative; height: 270px; }

    .modern-report-table { width: 100%; border-collapse: collapse; }
    .modern-report-table th { text-align: left; padding: 14px 15px; background: #f8fafc; color: #475569; font-size: 0.82rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
    .modern-report-table td { padding: 14px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .modern-report-table tr:hover { background: #fdfdfd; }
    .modern-report-table tfoot td { background: #f8fafc; font-weight: 800; color: #0f172a; border-top: 2px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }

    .report-data-note {
        display: flex; align-items: flex-start; gap: 10px; margin-top: 14px; padding: 12px 14px;
        background: color-mix(in srgb, var(--secondary-color, #007bff) 7%, white);
        border: 1px solid color-mix(in srgb, var(--secondary-color, #007bff) 22%, white);
        border-radius: 8px; color: #475569; font-size: .86rem; line-height: 1.5;
    }
    .report-data-note i { color: var(--secondary-color, #007bff); margin-top: 3px; }
    .report-empty { text-align: center; color: #94a3b8; padding: 28px 15px !important; }

    .rel-exec-ia { background: linear-gradient(135deg, #faf5ff 0%, #eff6ff 100%); border: 1px solid #ddd6fe; border-radius: 14px; padding: 18px 20px; margin: 25px 0 0; }
    .rel-exec-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .rel-exec-head h4 { margin: 0; color: #5b21b6; font-size: 1.05rem; display: flex; align-items: center; gap: 8px; }
    .rel-exec-btn { background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: .85rem; cursor: pointer; }
    .rel-exec-btn:hover { filter: brightness(1.08); }
    .rel-exec-btn:disabled { opacity: .6; cursor: default; }
    .rel-exec-texto { margin-top: 14px; color: #4c1d95; font-size: .92rem; line-height: 1.65; }

    .rel-progress { height: 8px; background: #eef2f7; border-radius: 6px; overflow: hidden; margin-top: 6px; min-width: 90px; }
    .rel-progress > span { display: block; height: 100%; border-radius: 6px; background: var(--secondary-color, #007bff); }

    .badge-soft { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: .74rem; font-weight: 800; }
    .badge-soft.warn { background: #fff7ed; color: #c2410c; }
    .badge-soft.danger { background: #fef2f2; color: #b91c1c; }
    .badge-soft.ok { background: #ecfdf5; color: #047857; }

    @media (max-width: 900px) { .rel-two-col { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
        .report-header-bar { flex-direction: column; align-items: stretch; }
        .rel-exec-head { flex-direction: column; align-items: stretch; }
        .kpi-row { grid-template-columns: 1fr; }
        .report-header-actions { width: 100%; flex-wrap: wrap; }
        .report-period-form { width: 100%; grid-template-columns: 1fr 1fr; }
        .report-period-form .btn-filtrar { grid-column: 1 / -1; }
        .btn-print-report { justify-content: center; }
        .table-card { padding: 16px; overflow-x: auto; }
        .rel-chart-grid { grid-template-columns: 1fr; }
        .rel-chart-box { height: 240px; }
    }
</style>

<div class="relatorios-layout">

    <div class="report-header-bar">
        <div class="title">
            <h3>Central de Relatórios</h3>
            <p>Análise de dados: <strong style="color:var(--secondary-color);"><?= date('d/m/Y', strtotime($data_inicio_filtro)) ?></strong> até <strong style="color:var(--secondary-color);"><?= date('d/m/Y', strtotime($data_fim_filtro)) ?></strong>
               <span style="color:#94a3b8;">· comparado com <?= htmlspecialchars($periodoAnteriorLabel) ?></span></p>
        </div>

        <div class="report-header-actions">
            <div>
                <form method="GET" class="report-period-form" id="reportPeriodForm">
                    <input type="hidden" name="tab" value="relatorios">
                    <label class="report-filter-field">
                        <span>Início</span>
                        <input type="date" name="data_inicio" id="relDataInicio" value="<?= $data_inicio_filtro ?>" class="modern-input">
                    </label>
                    <label class="report-filter-field">
                        <span>Fim</span>
                        <input type="date" name="data_fim" id="relDataFim" value="<?= $data_fim_filtro ?>" class="modern-input">
                    </label>
                    <button type="submit" class="btn-filtrar"><i class="fa fa-filter"></i> Filtrar</button>
                </form>
                <div class="report-quick-ranges">
                    <button type="button" data-range="hoje">Hoje</button>
                    <button type="button" data-range="7dias">7 dias</button>
                    <button type="button" data-range="mes">Este mês</button>
                    <button type="button" data-range="mespassado">Mês passado</button>
                    <button type="button" data-range="ano">Este ano</button>
                </div>
            </div>
            <a href="imprimir_relatorio.php?data_inicio=<?= $data_inicio_filtro ?>&data_fim=<?= $data_fim_filtro ?>" target="_blank" class="btn-print-report">
                <i class="fa fa-print"></i> Imprimir / PDF
            </a>
        </div>
    </div>

    <nav class="report-nav">
        <button class="report-nav-btn active" data-section="sec-financeiro"><i class="fa fa-dollar-sign"></i> Financeiro</button>
        <button class="report-nav-btn" data-section="sec-equipe"><i class="fa fa-user-tie"></i> Equipe</button>
        <button class="report-nav-btn" data-section="sec-clientes"><i class="fa fa-users"></i> Clientes & CRM</button>
        <button class="report-nav-btn" data-section="sec-servicos"><i class="fa fa-cut"></i> Serviços & Produtos</button>
        <button class="report-nav-btn" data-section="sec-assinaturas"><i class="fa fa-crown"></i> Assinaturas</button>
    </nav>

    <!-- ==================== FINANCEIRO ==================== -->
    <div id="sec-financeiro" class="report-section active">
        <div class="kpi-row">
            <div class="report-kpi-card">
                <div class="icon" style="background:#ecfdf5; color:#10b981;"><i class="fa fa-wallet"></i></div>
                <div class="data">
                    <h4>Faturamento Bruto</h4>
                    <div class="val">R$ <?= number_format($receitaTotal, 2, ',', '.') ?></div>
                    <?= $fmtVar($comparativoPeriodo['receita']['var']) ?>
                    <span class="rel-delta-hint">Anterior: R$ <?= number_format($comparativoPeriodo['receita']['anterior'], 2, ',', '.') ?></span>
                </div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fef2f2; color:#ef4444;"><i class="fa fa-receipt"></i></div>
                <div class="data">
                    <h4>Custos Totais</h4>
                    <div class="val">R$ <?= number_format($totalCustosPeriodo, 2, ',', '.') ?></div>
                    <span class="rel-delta-hint">Comissões + Despesas + CMV</span>
                </div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:<?= $lucroLiquidoPeriodo >= 0 ? '#f0fdf4' : '#fef2f2' ?>; color:<?= $lucroLiquidoPeriodo >= 0 ? '#166534' : '#991b1b' ?>;"><i class="fa fa-hand-holding-dollar"></i></div>
                <div class="data">
                    <h4>Lucro Líquido</h4>
                    <div class="val" style="color: <?= $lucroLiquidoPeriodo >= 0 ? '#166534' : '#991b1b' ?>;">R$ <?= number_format($lucroLiquidoPeriodo, 2, ',', '.') ?></div>
                    <?= $fmtVar($comparativoPeriodo['lucro']['var']) ?>
                    <span class="rel-delta-hint">Margem: <?= number_format($margemLucroPeriodo, 1, ',', '.') ?>%</span>
                </div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#eff6ff; color:#3b82f6;"><i class="fa fa-chart-line"></i></div>
                <div class="data">
                    <h4>Ticket Médio</h4>
                    <div class="val">R$ <?= number_format($ticketMedio, 2, ',', '.') ?></div>
                    <?= $fmtVar($comparativoPeriodo['ticket']['var']) ?>
                    <span class="rel-delta-hint"><?= (int)$comparativoPeriodo['atendimentos']['atual'] ?> atendimento(s)</span>
                </div>
            </div>
        </div>

        <?php
        $dadosRelatorioIA = "Período: " . date('d/m/Y', strtotime($data_inicio_filtro)) . " a " . date('d/m/Y', strtotime($data_fim_filtro)) . "\n"
            . "Faturamento bruto: R$ " . number_format($receitaTotal, 2, ',', '.') . " (anterior R$ " . number_format($comparativoPeriodo['receita']['anterior'], 2, ',', '.') . ")\n"
            . "  - Serviços: R$ " . number_format($receitaServicos ?? 0, 2, ',', '.') . " | Assinaturas: R$ " . number_format($receitaPlanos ?? 0, 2, ',', '.') . " | Produtos: R$ " . number_format($totalProdutos ?? 0, 2, ',', '.') . "\n"
            . "Comissões: R$ " . number_format($totalComissoesPeriodo, 2, ',', '.') . " | Despesas: R$ " . number_format($totalDespesasPeriodo, 2, ',', '.') . " | CMV: R$ " . number_format($relCmvProdutos, 2, ',', '.') . "\n"
            . "Lucro líquido: R$ " . number_format($lucroLiquidoPeriodo, 2, ',', '.') . " (anterior R$ " . number_format($comparativoPeriodo['lucro']['anterior'], 2, ',', '.') . ") | Margem: " . number_format($margemLucroPeriodo, 1, ',', '.') . "%\n"
            . "Ticket médio: R$ " . number_format($ticketMedio, 2, ',', '.') . "\n"
            . "Gorjetas (equipe): R$ " . number_format($relGorjetas, 2, ',', '.') . "\n"
            . "Novos clientes no período: " . (int)($statsClientes['novos_no_periodo'] ?? 0);
        ?>
        <div class="rel-exec-ia">
            <div class="rel-exec-head">
                <h4><i class="fa fa-brain"></i> Resumo executivo (IA)</h4>
                <button type="button" id="btn-relatorio-exec" class="rel-exec-btn" data-dados="<?= htmlspecialchars($dadosRelatorioIA) ?>"><i class="fa fa-wand-magic-sparkles"></i> Gerar análise</button>
            </div>
            <div id="rel-exec-texto" class="rel-exec-texto"><i class="fa fa-info-circle"></i> Deixe a IA analisar os números do período e sugerir prioridades para o próximo.</div>
        </div>

        <div class="rel-chart-grid">
            <div class="rel-chart-card rel-chart-wide">
                <div class="rel-chart-header"><i class="fa fa-chart-column"></i> Receita bruta por dia</div>
                <div class="rel-chart-box"><canvas id="receitaVsDespesaChart"></canvas></div>
            </div>
            <div class="rel-chart-card">
                <div class="rel-chart-header"><i class="fa fa-chart-pie"></i> Composição da receita</div>
                <div class="rel-chart-box"><canvas id="fontesReceitaChart"></canvas></div>
            </div>
            <div class="rel-chart-card">
                <div class="rel-chart-header"><i class="fa fa-scale-balanced"></i> Este período vs. anterior</div>
                <div class="rel-chart-box"><canvas id="comparativoPeriodoChart"></canvas></div>
            </div>
            <div class="rel-chart-card">
                <div class="rel-chart-header"><i class="fa fa-credit-card"></i> Entradas por forma de pagamento</div>
                <div class="rel-chart-box"><canvas id="formasPagamentoChart"></canvas></div>
            </div>
            <div class="rel-chart-card">
                <div class="rel-chart-header"><i class="fa fa-file-invoice"></i> Despesas por categoria</div>
                <div class="rel-chart-box"><canvas id="despesasCategoriaChart"></canvas></div>
            </div>
            <div class="rel-chart-card">
                <div class="rel-chart-header"><i class="fa fa-tags"></i> Descontos por tipo</div>
                <div class="rel-chart-box"><canvas id="analiseDescontosChart"></canvas></div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <div class="tc-title"><i class="fa fa-scale-unbalanced"></i> Demonstrativo de Resultado (DRE Simplificado)</div>
                <div class="tc-actions"><?= $csvBtn('financeiro') ?></div>
            </div>
            <table class="modern-report-table">
                <thead>
                    <tr><th>Categoria</th><th>Detalhes</th><th class="num">Valor</th></tr>
                </thead>
                <tbody>
                    <tr><td><span class="badge-modern badge-green">Entrada</span> Serviços</td><td>Receita líquida dos atendimentos</td><td class="num" style="color:#10b981; font-weight:700;">+ R$ <?= number_format($receitaServicos, 2, ',', '.') ?></td></tr>
                    <tr><td><span class="badge-modern badge-green">Entrada</span> Assinaturas</td><td><?= count($pagamentosAssinaturaPeriodo) ?> mensalidade(s) no período</td><td class="num" style="color:#10b981; font-weight:700;">+ R$ <?= number_format($receitaPlanos, 2, ',', '.') ?></td></tr>
                    <tr><td><span class="badge-modern badge-green">Entrada</span> Produtos</td><td>Pomadas, óleos, bebidas, etc.</td><td class="num" style="color:#10b981; font-weight:700;">+ R$ <?= number_format($totalProdutos, 2, ',', '.') ?></td></tr>
                    <tr style="background:#f8fafc;"><td colspan="2"><strong>Receita Bruta Total</strong></td><td class="num" style="font-weight:800;">R$ <?= number_format($receitaTotal, 2, ',', '.') ?></td></tr>
                    <tr><td><span class="badge-modern badge-red" style="background:#fef2f2; color:#991b1b; border-color:#fecaca;">Saída</span> Comissões</td><td>Repasse bruto para a equipe</td><td class="num" style="color:#ef4444; font-weight:700;">- R$ <?= number_format($totalComissoesPeriodo, 2, ',', '.') ?></td></tr>
                    <tr><td><span class="badge-modern badge-red" style="background:#fef2f2; color:#991b1b; border-color:#fecaca;">Saída</span> Despesas Operacionais</td><td>Contas, insumos, aluguel, etc.</td><td class="num" style="color:#ef4444; font-weight:700;">- R$ <?= number_format($totalDespesasPeriodo, 2, ',', '.') ?></td></tr>
                    <tr><td><span class="badge-modern badge-red" style="background:#fef2f2; color:#991b1b; border-color:#fecaca;">Saída</span> Custo de Produtos (CMV)</td><td>Custo dos produtos vendidos</td><td class="num" style="color:#ef4444; font-weight:700;">- R$ <?= number_format($relCmvProdutos, 2, ',', '.') ?></td></tr>
                    <tr style="background:<?= $lucroLiquidoPeriodo >= 0 ? '#f0fdf4' : '#fef2f2' ?>;"><td colspan="2"><strong>Lucro Líquido</strong> <small style="color:#64748b;">(margem <?= number_format($margemLucroPeriodo, 1, ',', '.') ?>%)</small></td><td class="num" style="font-weight:800; color:<?= $lucroLiquidoPeriodo >= 0 ? '#166534' : '#991b1b' ?>;">R$ <?= number_format($lucroLiquidoPeriodo, 2, ',', '.') ?></td></tr>
                </tbody>
            </table>
            <div class="report-data-note">
                <i class="fa fa-circle-info"></i>
                <span><strong>Gorjetas do período: R$ <?= number_format($relGorjetas, 2, ',', '.') ?></strong> — repassadas 100% à equipe e por isso ficam fora do resultado (não são receita da barbearia).</span>
            </div>
            <?php if ($pagamentosAssinaturaEstimados > 0): ?>
                <div class="report-data-note">
                    <i class="fa fa-circle-info"></i>
                    <span><strong><?= $pagamentosAssinaturaEstimados ?> mensalidade(s) anterior(es) ao novo histórico</strong> foram estimadas pelo valor atual do plano.</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="rel-two-col">
            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-credit-card"></i> Entradas por Forma de Pagamento</div>
                    <div class="tc-actions"><?= $csvBtn('formas_pagamento') ?></div>
                </div>
                <?php
                $relFormasLabels = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Cartão de débito', 'credito' => 'Cartão de crédito', 'outro' => 'Outro', 'nao_informado' => 'Não informado'];
                $algumaForma = false;
                ?>
                <table class="modern-report-table">
                    <thead><tr><th>Forma</th><th class="num">%</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                        <?php foreach ($relFormasLabels as $fk => $flabel):
                            if (($relCaixaPorForma[$fk] ?? 0) <= 0) continue;
                            $algumaForma = true;
                            $fpct = $relTotalCaixa > 0 ? ($relCaixaPorForma[$fk] / $relTotalCaixa) * 100 : 0;
                        ?>
                            <tr>
                                <td><?= $flabel ?></td>
                                <td class="num" style="color:#94a3b8;"><?= number_format($fpct, 0) ?>%</td>
                                <td class="num" style="font-weight:700;">R$ <?= number_format($relCaixaPorForma[$fk], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$algumaForma): ?>
                            <tr><td colspan="3" class="report-empty">Sem forma de pagamento informada na comanda no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($algumaForma): ?>
                    <tfoot><tr><td>Total recebido</td><td></td><td class="num">R$ <?= number_format($relTotalCaixa, 2, ',', '.') ?></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-file-invoice"></i> Despesas por Categoria</div>
                    <div class="tc-actions"><?= $csvBtn('despesas_categoria') ?></div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Categoria</th><th class="num">Lanç.</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                        <?php foreach ($despesasPorCategoria as $cat => $info): ?>
                            <tr>
                                <td><?= htmlspecialchars($cat) ?></td>
                                <td class="num" style="color:#94a3b8;"><?= (int)$info['quantidade'] ?></td>
                                <td class="num" style="color:#ef4444; font-weight:700;">R$ <?= number_format($info['total'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($despesasPorCategoria)): ?>
                            <tr><td colspan="3" class="report-empty">Nenhuma despesa lançada no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($despesasPorCategoria)): ?>
                    <tfoot><tr><td>Total de despesas</td><td></td><td class="num">R$ <?= number_format($totalDespesasPeriodo, 2, ',', '.') ?></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <div class="tc-title"><i class="fa fa-list"></i> Despesas Detalhadas (<?= count($despesasRelatorio['itens']) ?> lançamento(s))</div>
                <div class="tc-actions"><?= $csvBtn('despesas') ?></div>
            </div>
            <table class="modern-report-table">
                <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th>Status</th><th class="num">Valor</th></tr></thead>
                <tbody>
                    <?php foreach ($despesasRelatorio['itens'] as $despesa): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($despesa['data_competencia'])) ?></td>
                            <td><?= htmlspecialchars($despesa['descricao'] ?? 'Despesa') ?></td>
                            <td><?= htmlspecialchars($despesa['categoria'] ?? 'Outros') ?></td>
                            <td>
                                <?php $st = strtolower((string)($despesa['status'] ?? 'pendente')); ?>
                                <span class="badge-soft <?= $st === 'pago' ? 'ok' : 'warn' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
                            </td>
                            <td class="num" style="font-weight:700;">R$ <?= number_format((float)$despesa['valor'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($despesasRelatorio['itens'])): ?>
                        <tr><td colspan="5" class="report-empty">Nenhuma despesa no período.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== EQUIPE ==================== -->
    <div id="sec-equipe" class="report-section">
        <div class="kpi-row">
            <div class="report-kpi-card">
                <div class="icon" style="background:#eef2ff; color:#6366f1;"><i class="fa fa-trophy"></i></div>
                <div class="data"><h4>Destaque do Período</h4><div class="val" style="font-size:1.15rem;"><?= htmlspecialchars($melhorBarbeiro['nome']) ?></div><span class="rel-delta-hint">R$ <?= number_format($melhorBarbeiro['valor'], 2, ',', '.') ?> em faturamento</span></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#ecfdf5; color:#10b981;"><i class="fa fa-hand-holding-dollar"></i></div>
                <div class="data"><h4>Total em Comissões</h4><div class="val">R$ <?= number_format($totalComissoesPeriodo, 2, ',', '.') ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fff7ed; color:#f97316;"><i class="fa fa-coins"></i></div>
                <div class="data"><h4>Gorjetas (equipe)</h4><div class="val">R$ <?= number_format($relGorjetas, 2, ',', '.') ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fefce8; color:#f59e0b;"><i class="fa fa-star"></i></div>
                <div class="data"><h4>Avaliação Média</h4><div class="val"><?= $mediaAvaliacoesPeriodo ?></div></div>
            </div>
        </div>

        <div class="rel-chart-grid">
            <div class="rel-chart-card"><div class="rel-chart-header"><i class="fa fa-user-tie"></i> Ocupação por profissional</div><div class="rel-chart-box"><canvas id="ocupacaoBarbeiroChart"></canvas></div></div>
            <div class="rel-chart-card"><div class="rel-chart-header"><i class="fa fa-calendar-week"></i> Movimento por dia da semana</div><div class="rel-chart-box"><canvas id="agendamentosPorDiaSemanaChart"></canvas></div></div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <div class="tc-title"><i class="fa fa-file-invoice-dollar"></i> Desempenho e Comissões por Profissional</div>
                <div class="tc-actions"><?= $csvBtn('equipe') ?></div>
            </div>
            <table class="modern-report-table" style="font-size: 0.95rem;">
                <thead>
                    <tr><th>Profissional</th><th class="num">Atend.</th><th class="num">Faturamento</th><th class="num">Ticket</th><th class="num">Comissão</th><th class="num">Vales</th><th class="num">Líquido</th><th class="num">Nota</th></tr>
                </thead>
                <tbody>
                    <?php foreach($dados_equipe_relatorio as $id => $stats):
                        $notasPeriodo = $avaliacoesPeriodoBarbeiro[$id] ?? [];
                        $media = $notasPeriodo ? number_format(array_sum($notasPeriodo) / count($notasPeriodo), 1, ',', '.') : 'N/A';
                        $ticketBarbeiro = $stats['atendimentos'] > 0 ? $stats['faturamento_total'] / $stats['atendimentos'] : 0;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($stats['nome']) ?></strong>
                            <span class="badge-modern badge-green" style="margin-left: 6px; font-size: 0.72rem;"><?= $stats['perc'] ?>%</span>
                            <small class="commission-rule-summary"><i class="fa fa-crown"></i> <?= htmlspecialchars($stats['regra_assinatura']) ?></small>
                        </td>
                        <td class="num"><?= $stats['atendimentos'] ?></td>
                        <td class="num" style="color:#0f172a; font-weight:600;">R$ <?= number_format($stats['faturamento_total'], 2, ',', '.') ?></td>
                        <td class="num" style="color:#64748b;">R$ <?= number_format($ticketBarbeiro, 2, ',', '.') ?></td>
                        <td class="num" style="color:#64748b;">
                            R$ <?= number_format($stats['comissao_bruta'], 2, ',', '.') ?>
                            <?php if ($stats['comissao_assinatura'] > 0): ?>
                                <small class="commission-plan-detail"><i class="fa fa-crown"></i> Planos: R$ <?= number_format($stats['comissao_assinatura'], 2, ',', '.') ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="color:#ef4444; font-weight:600;"><?= $stats['vales'] > 0 ? '- R$ ' . number_format($stats['vales'], 2, ',', '.') : 'R$ 0,00' ?></td>
                        <td class="num" style="color:#10b981; font-weight:800;">R$ <?= number_format($stats['comissao_liquida'], 2, ',', '.') ?></td>
                        <td class="num"><span style="color:#f59e0b;"><i class="fa fa-star"></i> <?= $media ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($dados_equipe_relatorio)): ?>
                        <tr><td colspan="8" class="report-empty">Nenhum profissional encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($dados_equipe_relatorio)): ?>
                <tfoot>
                    <tr>
                        <td>Totais</td>
                        <td class="num"><?= array_sum(array_column($dados_equipe_relatorio, 'atendimentos')) ?></td>
                        <td class="num">R$ <?= number_format(array_sum(array_column($dados_equipe_relatorio, 'faturamento_total')), 2, ',', '.') ?></td>
                        <td></td>
                        <td class="num">R$ <?= number_format(array_sum(array_column($dados_equipe_relatorio, 'comissao_bruta')), 2, ',', '.') ?></td>
                        <td class="num">R$ <?= number_format(array_sum(array_column($dados_equipe_relatorio, 'vales')), 2, ',', '.') ?></td>
                        <td class="num">R$ <?= number_format(array_sum(array_column($dados_equipe_relatorio, 'comissao_liquida')), 2, ',', '.') ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ==================== CLIENTES & CRM ==================== -->
    <div id="sec-clientes" class="report-section">
        <div class="kpi-row">
            <div class="report-kpi-card">
                <div class="icon" style="background:#fdf4ff; color:#a855f7;"><i class="fa fa-user-plus"></i></div>
                <div class="data"><h4>Novos Clientes</h4><div class="val"><?= (int)($statsClientes['novos_no_periodo'] ?? 0) ?></div><?= $fmtVar($comparativoPeriodo['atendimentos']['var']) ?></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fff7ed; color:#f97316;"><i class="fa fa-heartbeat"></i></div>
                <div class="data"><h4>Intervalo Médio de Retorno</h4><div class="val"><?= $frequenciaMedia === 'N/D' ? 'N/D' : $frequenciaMedia . ' dias' ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fef2f2; color:#ef4444;"><i class="fa fa-user-clock"></i></div>
                <div class="data"><h4>Clientes em Risco (churn)</h4><div class="val"><?= count($clientesEmRisco) ?></div><span class="rel-delta-hint">Sem retorno há 90+ dias</span></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fefce8; color:#f59e0b;"><i class="fa fa-tags"></i></div>
                <div class="data"><h4>Descontos Aplicados</h4><div class="val">R$ <?= number_format($totalDescontos, 2, ',', '.') ?></div></div>
            </div>
        </div>

        <div class="rel-chart-grid">
            <div class="rel-chart-card"><div class="rel-chart-header"><i class="fa fa-user-plus"></i> Novos vs. recorrentes</div><div class="rel-chart-box"><canvas id="novosClientesChart"></canvas></div></div>
            <div class="rel-chart-card"><div class="rel-chart-header"><i class="fa fa-clock"></i> Horários de pico</div><div class="rel-chart-box"><canvas id="horariosPicoChart"></canvas></div></div>
        </div>

        <div class="rel-two-col">
            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-crown"></i> Maiores Compradores</div>
                    <div class="tc-actions"><?= $csvBtn('clientes') ?></div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>#</th><th>Cliente</th><th class="num">Total Gasto</th></tr></thead>
                    <tbody>
                        <?php
                        arsort($topClientes);
                        $posicao = 1;
                        foreach(array_slice($topClientes, 0, 15, true) as $nome => $valor):
                        ?>
                        <tr>
                            <td style="color:#94a3b8; font-weight:700;"><?= $posicao++ ?>º</td>
                            <td><?= htmlspecialchars($nome) ?></td>
                            <td class="num" style="font-weight:700;">R$ <?= number_format($valor, 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topClientes)): ?>
                            <tr><td colspan="3" class="report-empty">Nenhum atendimento concluído no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-user-clock"></i> Clientes em Risco</div>
                    <div class="tc-actions"><?= $csvBtn('clientes_risco') ?></div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Cliente</th><th>Contato</th><th class="num">Último atend.</th></tr></thead>
                    <tbody>
                        <?php foreach(array_slice($clientesEmRisco, 0, 15) as $cliente): ?>
                        <tr>
                            <td><?= htmlspecialchars($cliente['nome'] ?? 'Cliente') ?></td>
                            <td style="color:#64748b; font-size:.85rem;"><?= htmlspecialchars($cliente['telefone'] ?? $cliente['email'] ?? '—') ?></td>
                            <td class="num"><span class="badge-soft danger"><?= isset($cliente['ultimo_agendamento']) ? date('d/m/Y', strtotime($cliente['ultimo_agendamento'])) : '—' ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientesEmRisco)): ?>
                            <tr><td colspan="3" class="report-empty">Nenhum cliente recorrente em risco. 🎉</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <div class="tc-title"><i class="fa fa-cake-candles"></i> Aniversariantes do Mês</div>
                <div class="tc-actions"><?= $csvBtn('aniversariantes') ?></div>
            </div>
            <table class="modern-report-table">
                <thead><tr><th>Cliente</th><th>Contato</th><th class="num">Aniversário</th></tr></thead>
                <tbody>
                    <?php foreach($aniversariantesDoMes as $aniv): ?>
                    <tr>
                        <td><?= htmlspecialchars($aniv['nome'] ?? 'Cliente') ?></td>
                        <td style="color:#64748b; font-size:.85rem;"><?= htmlspecialchars($aniv['telefone'] ?? $aniv['email'] ?? '—') ?></td>
                        <td class="num"><span class="badge-soft ok"><i class="fa fa-gift"></i> <?= date('d/m', strtotime($aniv['data_nascimento'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($aniversariantesDoMes)): ?>
                        <tr><td colspan="3" class="report-empty">Nenhum aniversariante cadastrado neste mês.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== SERVIÇOS & PRODUTOS ==================== -->
    <div id="sec-servicos" class="report-section">
        <div class="kpi-row">
            <div class="report-kpi-card">
                <div class="icon" style="background:#ecfdf5; color:#10b981;"><i class="fa fa-check"></i></div>
                <div class="data"><h4>Atendimentos Concluídos</h4><div class="val"><?= (int)($contagemStatus['concluido'] ?? 0) ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fef2f2; color:#ef4444;"><i class="fa fa-ban"></i></div>
                <div class="data"><h4>Cancelamentos</h4><div class="val"><?= (int)($contagemStatus['cancelado'] ?? 0) ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#eff6ff; color:#3b82f6;"><i class="fa fa-percent"></i></div>
                <div class="data"><h4>Taxa de Conclusão</h4><div class="val"><?= number_format($taxaConclusaoPeriodo, 1, ',', '.') ?>%</div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fff7ed; color:#f97316;"><i class="fa fa-chair"></i></div>
                <div class="data"><h4>Ocupação Média</h4><div class="val"><?= number_format($taxaOcupacaoMedia, 1, ',', '.') ?>%</div></div>
            </div>
        </div>

        <div class="rel-two-col">
            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-chart-bar"></i> Serviços Mais Realizados</div>
                    <div class="tc-actions"><?= $csvBtn('servicos') ?></div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Serviço / Combo</th><th class="num">Realizações</th><th class="num">Faturamento</th></tr></thead>
                    <tbody>
                        <?php
                        arsort($rankingServicosIds);
                        foreach($rankingServicosIds as $sid => $qtd):
                            $serv = $servicosArr[$sid] ?? ($combosArr[$sid] ?? null);
                            if(!$serv) continue;
                            $faturamentoServ = (float)$serv['valor'] * $qtd;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($serv['nome']) ?></td>
                            <td class="num"><?= $qtd ?>x</td>
                            <td class="num" style="color:#059669; font-weight:700;">R$ <?= number_format($faturamentoServ, 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rankingServicosIds)): ?>
                            <tr><td colspan="3" class="report-empty">Nenhum serviço concluído no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-layer-group"></i> Combinações Mais Frequentes</div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Combinação</th><th class="num">Ocorrências</th></tr></thead>
                    <tbody>
                        <?php foreach($servicosMaisCombinados as $combo): ?>
                        <tr>
                            <td><?= htmlspecialchars($combo['combinacao']) ?></td>
                            <td class="num"><?= (int)$combo['ocorrencias'] ?>x</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($servicosMaisCombinados)): ?>
                            <tr><td colspan="2" class="report-empty">Nenhuma combinação de serviços no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <div class="tc-title"><i class="fa fa-box-open"></i> Vendas de Produtos (Receita, Custo e Margem)</div>
                <div class="tc-actions"><?= $csvBtn('produtos') ?></div>
            </div>
            <table class="modern-report-table">
                <thead><tr><th>Produto</th><th class="num">Qtd</th><th class="num">Receita</th><th class="num">Custo (CMV)</th><th class="num">Margem</th><th class="num">%</th></tr></thead>
                <tbody>
                    <?php
                    $somaRec = 0; $somaCusto = 0; $somaMargem = 0;
                    foreach($lucratividadeProdutos as $nomeProd => $info):
                        $somaRec += $info['receita']; $somaCusto += $info['custo']; $somaMargem += $info['margem'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($nomeProd) ?></td>
                        <td class="num"><?= (int)$info['quantidade'] ?></td>
                        <td class="num" style="color:#059669; font-weight:700;">R$ <?= number_format($info['receita'], 2, ',', '.') ?></td>
                        <td class="num" style="color:#ef4444;">R$ <?= number_format($info['custo'], 2, ',', '.') ?></td>
                        <td class="num" style="font-weight:700;">R$ <?= number_format($info['margem'], 2, ',', '.') ?></td>
                        <td class="num"><span class="badge-soft <?= $info['margem_perc'] >= 40 ? 'ok' : ($info['margem_perc'] >= 15 ? 'warn' : 'danger') ?>"><?= number_format($info['margem_perc'], 0) ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lucratividadeProdutos)): ?>
                        <tr><td colspan="6" class="report-empty">Nenhum produto vendido no período.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($lucratividadeProdutos)): ?>
                <tfoot>
                    <tr>
                        <td>Totais</td><td></td>
                        <td class="num">R$ <?= number_format($somaRec, 2, ',', '.') ?></td>
                        <td class="num">R$ <?= number_format($somaCusto, 2, ',', '.') ?></td>
                        <td class="num">R$ <?= number_format($somaMargem, 2, ',', '.') ?></td>
                        <td class="num"><?= $somaRec > 0 ? number_format(($somaMargem / $somaRec) * 100, 0) : 0 ?>%</td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            <?php if (!empty($produtosEstoqueBaixo)): ?>
            <div class="report-data-note" style="background:#fff7ed; border-color:#fed7aa;">
                <i class="fa fa-triangle-exclamation" style="color:#f97316;"></i>
                <span><strong><?= count($produtosEstoqueBaixo) ?> produto(s) no ponto de reposição:</strong>
                <?php
                    $avisos = [];
                    foreach (array_slice($produtosEstoqueBaixo, 0, 8) as $p) {
                        $avisos[] = htmlspecialchars($p['nome'] ?? 'Produto') . ' (' . (int)($p['quantidade'] ?? 0) . ')';
                    }
                    echo implode(', ', $avisos);
                ?>.</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== ASSINATURAS ==================== -->
    <div id="sec-assinaturas" class="report-section">
        <div class="kpi-row">
            <div class="report-kpi-card">
                <div class="icon" style="background:#eef2ff; color:#6366f1;"><i class="fa fa-users-rays"></i></div>
                <div class="data"><h4>Assinantes Ativos</h4><div class="val"><?= (int)$totalAssinantesAtivos ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#ecfdf5; color:#10b981;"><i class="fa fa-arrows-rotate"></i></div>
                <div class="data"><h4>MRR (Receita Recorrente)</h4><div class="val">R$ <?= number_format($mrrAtual, 2, ',', '.') ?></div><span class="rel-delta-hint">Valor mensal contratado</span></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fdf4ff; color:#a855f7;"><i class="fa fa-user-plus"></i></div>
                <div class="data"><h4>Adesões no Período</h4><div class="val"><?= (int)$novasAssinaturasPeriodo ?></div></div>
            </div>
            <div class="report-kpi-card">
                <div class="icon" style="background:#fff7ed; color:#f97316;"><i class="fa fa-money-bill-wave"></i></div>
                <div class="data"><h4>Mensalidades Recebidas</h4><div class="val"><?= count($pagamentosAssinaturaPeriodo) ?></div><span class="rel-delta-hint">R$ <?= number_format($receitaPlanos, 2, ',', '.') ?></span></div>
            </div>
        </div>

        <div class="rel-chart-grid">
            <div class="rel-chart-card rel-chart-wide"><div class="rel-chart-header"><i class="fa fa-crown"></i> Planos mais assinados</div><div class="rel-chart-box"><canvas id="planosPopularesChart"></canvas></div></div>
        </div>

        <div class="rel-two-col">
            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-hourglass-half"></i> Assinaturas Vencendo (15 dias)</div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Cliente</th><th>Plano</th><th class="num">Vence em</th></tr></thead>
                    <tbody>
                        <?php foreach($assinaturasVencendo as $venc): ?>
                        <tr>
                            <td><?= htmlspecialchars($venc['nome_cliente'] ?? 'Cliente') ?></td>
                            <td><?= htmlspecialchars($venc['nome_plano'] ?? 'Plano') ?></td>
                            <td class="num"><span class="badge-soft warn"><?= date('d/m/Y', strtotime($venc['data_fim'])) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($assinaturasVencendo)): ?>
                            <tr><td colspan="3" class="report-empty">Nenhuma assinatura vencendo nos próximos 15 dias.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <div class="tc-title"><i class="fa fa-receipt"></i> Pagamentos no Período</div>
                    <div class="tc-actions"><?= $csvBtn('pagamentos_assinatura') ?></div>
                </div>
                <table class="modern-report-table">
                    <thead><tr><th>Data</th><th>Cliente</th><th>Plano</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                        <?php foreach(array_slice($pagamentosAssinaturaPeriodo, 0, 20) as $pag): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($pag['data_pagamento'])) ?></td>
                            <td><?= htmlspecialchars($clientesArr[$pag['cliente_id']]['nome'] ?? 'Cliente') ?></td>
                            <td><?= htmlspecialchars($planosArr[$pag['plano_id']]['nome'] ?? 'Plano') ?><?= !empty($pag['estimado']) ? ' <small style="color:#94a3b8;">(est.)</small>' : '' ?></td>
                            <td class="num" style="font-weight:700;">R$ <?= number_format((float)$pag['valor'], 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($pagamentosAssinaturaPeriodo)): ?>
                            <tr><td colspan="4" class="report-empty">Nenhum pagamento de assinatura no período.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const navBtns = document.querySelectorAll('.report-nav-btn');
    const sections = document.querySelectorAll('.report-section');

    navBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            navBtns.forEach(b => b.classList.remove('active'));
            sections.forEach(s => s.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.getAttribute('data-section')).classList.add('active');
            // Recalcula gráficos que ficaram ocultos ao trocar de aba.
            if (typeof renderRelatoriosCharts === 'function') { try { renderRelatoriosCharts(); } catch (e) {} }
        });
    });

    // Atalhos de período.
    const fmt = (d) => d.toISOString().slice(0, 10);
    const setRange = (ini, fim) => {
        const i = document.getElementById('relDataInicio');
        const f = document.getElementById('relDataFim');
        if (i && f) { i.value = fmt(ini); f.value = fmt(fim); document.getElementById('reportPeriodForm').submit(); }
    };
    document.querySelectorAll('.report-quick-ranges button').forEach(b => {
        b.addEventListener('click', () => {
            const hoje = new Date();
            const r = b.getAttribute('data-range');
            if (r === 'hoje') setRange(hoje, hoje);
            else if (r === '7dias') { const i = new Date(); i.setDate(i.getDate() - 6); setRange(i, hoje); }
            else if (r === 'mes') setRange(new Date(hoje.getFullYear(), hoje.getMonth(), 1), new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0));
            else if (r === 'mespassado') setRange(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1), new Date(hoje.getFullYear(), hoje.getMonth(), 0));
            else if (r === 'ano') setRange(new Date(hoje.getFullYear(), 0, 1), new Date(hoje.getFullYear(), 11, 31));
        });
    });

    // Resumo executivo (IA)
    const btnExec = document.getElementById('btn-relatorio-exec');
    if (btnExec) {
        btnExec.addEventListener('click', function () {
            const alvo = document.getElementById('rel-exec-texto');
            const original = btnExec.innerHTML;
            btnExec.disabled = true; btnExec.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analisando...';
            if (alvo) alvo.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A IA está analisando o período...';
            fetch('ajax_gemini.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ action: 'relatorio_executivo', dados_relatorio: btnExec.dataset.dados || '' })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error((data && data.error) || 'Falha na IA');
                if (alvo) alvo.innerHTML = (data.resposta || '').replace(/\n/g, '<br>');
            })
            .catch(function (err) { if (alvo) alvo.innerHTML = '<span style="color:#dc2626;"><i class="fa fa-triangle-exclamation"></i> ' + err.message + '</span>'; })
            .finally(function () { btnExec.disabled = false; btnExec.innerHTML = original; });
        });
    }
});
</script>

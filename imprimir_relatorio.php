<?php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/functions.php';

$configGeral = carregarConfigGeral();
$barbeirosArr = lerDados('barbeiros', [
    'id', 'nome', 'foto', 'username', 'password', 'status', 'servicos_ids',
    'comissao', 'comissao_produtos', 'comissao_assinatura_tipo', 'comissao_assinatura_valor'
]);
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots', 'categoria_id']);
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor', 'categoria_id']);
$despesasArr = lerDados('despesas', ['id', 'descricao', 'valor', 'data_vencimento', 'data_pagamento', 'status', 'categoria']);
$agendamentosArr = lerDados('agendamentos', [
    'id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data',
    'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes',
    'produtos_vendidos', 'plano_provisorio', 'cliente_id'
]);
$clientesArr = lerDados('clientes', [
    'id', 'nome', 'email', 'telefone', 'password_hash', 'data_nascimento',
    'foto_perfil', 'codigo_indicacao', 'cpf', 'indicado_por_id', 'status', 'confirmation_token'
]);
$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
$assinaturasClientesArr = lerDados('clientes_assinaturas', [
    'cliente_id', 'plano_id', 'data_inicio', 'data_fim', 'status', 'gateway',
    'gateway_subscription_id', 'gateway_status', 'ultimo_pagamento_id', 'cancelamento_em'
]);
$avaliacoesArr = lerDados('avaliacoes', [
    'id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp'
]);

[$dataInicio, $dataFim] = normalizarPeriodoRelatorio(
    $_GET['data_inicio'] ?? date('Y-m-01'),
    $_GET['data_fim'] ?? date('Y-m-t')
);
$hoje = date('Y-m-d');
$agendamentosFiltrados = array_filter($agendamentosArr, fn($agendamento) =>
    ($agendamento['data'] ?? '') >= $dataInicio && ($agendamento['data'] ?? '') <= $dataFim
);
$agendamentosConcluidos = array_filter($agendamentosFiltrados, fn($agendamento) =>
    ($agendamento['status'] ?? '') === 'concluido'
);

$resumoFinanceiro = calcularResumoFinanceiroRelatorio(
    $agendamentosConcluidos,
    $servicosArr,
    $combosArr,
    $assinaturasClientesArr,
    $planosArr,
    $dataInicio,
    $dataFim
);
$despesasRelatorio = calcularDespesasPeriodoRelatorio($despesasArr, $dataInicio, $dataFim);
$equipeRelatorio = calcularEquipeRelatorio(
    $agendamentosConcluidos,
    $barbeirosArr,
    $servicosArr,
    $combosArr,
    $dataInicio,
    $dataFim
);

// Gorjetas, CMV (custo dos produtos), caixa por forma e lucratividade por produto.
$relCaixaDados = calcularCaixaGorjetasCmv($dataInicio, $dataFim, $servicosArr, $combosArr);
$relTotalGorjetas = $relCaixaDados['gorjetas'];
$relCmvProdutos = $relCaixaDados['cmv'];
$relCaixaPorForma = $relCaixaDados['caixa_por_forma'];
$relProdutosVendidos = $relCaixaDados['produtos'];
$despesasPorCategoria = calcularDespesasPorCategoria($despesasRelatorio['itens']);

$totalCustos = $despesasRelatorio['total'] + $equipeRelatorio['total_comissoes'] + $relCmvProdutos;
$lucroLiquido = $resumoFinanceiro['receita_total'] - $totalCustos;
$contagemStatus = getContagemStatusAgendamentos($agendamentosFiltrados);
$rankingServicos = getRankingServicos($agendamentosConcluidos, $servicosArr, $combosArr);
$topClientes = getTopClientesPorGasto($agendamentosConcluidos, $servicosArr, $combosArr);
$clientesEmRisco = getClientesEmRisco($agendamentosArr, $clientesArr, 90);
$novosVsRecorrentes = getNovosVsRecorrentes($agendamentosFiltrados, $agendamentosArr);
$frequenciaMedia = getFrequenciaMediaVisitas($agendamentosFiltrados);
$analiseDescontos = getAnaliseDescontos($agendamentosConcluidos);

$horariosTrabalho = [];
try {
    $stmtHorarios = getDB()->query("SELECT barbeiro_id, dia, inicio, fim FROM horarios_trabalho");
    $horariosTrabalho = $stmtHorarios ? $stmtHorarios->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
}
$taxasOcupacao = [];
foreach ($barbeirosArr as $id => $barbeiro) {
    $taxasOcupacao[$id] = getTaxaOcupacaoBarbeiro(
        $id,
        $dataInicio,
        $dataFim,
        $agendamentosFiltrados,
        $horariosTrabalho,
        $servicosArr,
        $combosArr
    );
}
$ocupacaoMedia = $taxasOcupacao ? array_sum($taxasOcupacao) / count($taxasOcupacao) : 0;

$assinantesAtivos = 0;
$mrrAtual = 0;
$assinaturasVencendo = [];
foreach ($assinaturasClientesArr as $assinatura) {
    $ativa = in_array($assinatura['status'] ?? '', ['ativo', 'cancelamento_agendado'], true)
        && ($assinatura['data_fim'] ?? '') >= $hoje;
    if (!$ativa) {
        continue;
    }
    $assinantesAtivos++;
    $mrrAtual += (float)($planosArr[$assinatura['plano_id']]['valor'] ?? 0);
    if (($assinatura['data_fim'] ?? '') <= date('Y-m-d', strtotime('+15 days'))) {
        $assinaturasVencendo[] = $assinatura;
    }
}
$adesoesPeriodo = count(array_filter(
    $resumoFinanceiro['pagamentos_assinatura'],
    fn($pagamento) => ($pagamento['tipo'] ?? '') === 'adesao'
));

$avaliacoesPeriodo = array_filter($avaliacoesArr, function ($avaliacao) use ($dataInicio, $dataFim) {
    $data = substr((string)($avaliacao['timestamp'] ?? ''), 0, 10);
    return $data >= $dataInicio && $data <= $dataFim;
});
$mediaAvaliacoes = $avaliacoesPeriodo
    ? array_sum(array_map(fn($avaliacao) => (float)($avaliacao['rating'] ?? 0), $avaliacoesPeriodo)) / count($avaliacoesPeriodo)
    : 0;

$tema = [
    'primary-color' => '#20242b',
    'secondary-color' => '#9a7442',
    'text-color' => '#344054'
];
$arquivoTema = __DIR__ . '/_dados/theme_config.json';
if (is_file($arquivoTema)) {
    $temaSalvo = json_decode((string)file_get_contents($arquivoTema), true);
    if (is_array($temaSalvo)) {
        $tema = array_merge($tema, $temaSalvo);
    }
}
$corSegura = function ($valor, $padrao) {
    return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$valor) ? $valor : $padrao;
};
$corPrimaria = $corSegura($tema['primary-color'] ?? '', '#20242b');
$corDestaque = $corSegura($tema['secondary-color'] ?? '', '#9a7442');
$corTexto = $corSegura($tema['text-color'] ?? '', '#344054');
$logoPath = (string)($configGeral['logo_path'] ?? '');
$mostrarLogo = $logoPath !== '' && is_file(__DIR__ . '/' . ltrim($logoPath, '/\\'));
$periodoFormatado = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
$taxaConclusao = ($contagemStatus['total'] ?? 0) > 0
    ? (($contagemStatus['concluido'] ?? 0) / $contagemStatus['total']) * 100
    : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Gerencial - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand: <?= htmlspecialchars($corPrimaria) ?>;
            --accent: <?= htmlspecialchars($corDestaque) ?>;
            --text: <?= htmlspecialchars($corTexto) ?>;
            --muted: #667085;
            --line: #e4e7ec;
            --soft: #f7f8fa;
            --positive: #16794b;
            --negative: #b42318;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 28px;
            background: #eef0f3;
            color: var(--text);
            font: 13px/1.45 Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .print-toolbar {
            width: min(1120px, 100%);
            margin: 0 auto 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .toolbar-group { display: flex; gap: 8px; }
        .toolbar-button {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: white;
            color: var(--text);
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .toolbar-button.primary { background: var(--accent); border-color: var(--accent); color: white; }
        .report {
            width: min(1120px, 100%);
            margin: 0 auto;
            padding: 34px 38px;
            background: white;
            border: 1px solid var(--line);
            box-shadow: 0 16px 45px rgba(16, 24, 40, .08);
        }
        .report-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            align-items: start;
            padding-bottom: 24px;
            border-bottom: 3px solid var(--brand);
        }
        .brand { display: flex; align-items: center; gap: 15px; min-width: 0; }
        .brand-logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            flex: 0 0 64px;
        }
        .brand-name { margin: 0; color: var(--brand); font-size: 25px; line-height: 1.15; }
        .brand-subtitle { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .report-meta { text-align: right; }
        .report-meta strong { display: block; color: var(--brand); font-size: 15px; }
        .report-meta span { display: block; margin-top: 5px; color: var(--muted); font-size: 11px; }
        .executive-title { margin: 26px 0 14px; }
        .executive-title span {
            display: block;
            color: var(--accent);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .executive-title h1 { margin: 4px 0 0; color: var(--brand); font-size: 22px; }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .kpi {
            min-height: 100px;
            padding: 15px;
            border: 1px solid var(--line);
            border-top: 3px solid var(--accent);
            border-radius: 8px;
            background: white;
        }
        .kpi small { display: block; color: var(--muted); font-weight: 700; }
        .kpi strong { display: block; margin-top: 8px; color: var(--brand); font-size: 20px; }
        .kpi em { display: block; margin-top: 5px; color: var(--muted); font-size: 10px; font-style: normal; }
        .kpi.negative { border-top-color: var(--negative); }
        .kpi.positive { border-top-color: var(--positive); }
        .section { margin-top: 28px; break-inside: avoid-page; }
        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 16px;
            margin-bottom: 10px;
        }
        .section-heading h2 { margin: 0; color: var(--brand); font-size: 16px; }
        .section-heading p { margin: 0; color: var(--muted); font-size: 10px; }
        .split { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .panel { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
        .panel-title {
            padding: 11px 13px;
            background: var(--soft);
            color: var(--brand);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th {
            padding: 9px 11px;
            background: var(--soft);
            color: var(--muted);
            border-bottom: 1px solid var(--line);
            font-size: 9px;
            text-align: left;
            text-transform: uppercase;
        }
        td { padding: 9px 11px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        tr { break-inside: avoid; }
        .money { text-align: right; white-space: nowrap; font-weight: 700; }
        .positive-text { color: var(--positive); }
        .negative-text { color: var(--negative); }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            padding: 12px;
        }
        .status-item { padding: 10px; border: 1px solid var(--line); border-radius: 6px; }
        .status-item strong { display: block; color: var(--brand); font-size: 17px; }
        .status-item span { color: var(--muted); font-size: 9px; text-transform: uppercase; }
        .data-note {
            margin-top: 10px;
            padding: 10px 12px;
            border-left: 3px solid var(--accent);
            background: var(--soft);
            color: var(--muted);
            font-size: 10px;
        }
        .empty { padding: 18px; text-align: center; color: var(--muted); }
        .report-footer {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            margin-top: 30px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 9px;
        }
        @media (max-width: 760px) {
            body { padding: 12px; }
            .report { padding: 22px 18px; }
            .report-header { grid-template-columns: 1fr; }
            .report-meta { text-align: left; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .split { grid-template-columns: 1fr; }
            .status-grid { grid-template-columns: 1fr 1fr; }
            .panel { overflow-x: auto; }
            .panel table { min-width: 460px; }
            .split .panel table { min-width: 0; }
        }
        @page { size: A4 portrait; margin: 11mm; }
        @media print {
            body { padding: 0; background: white; font-size: 10px; }
            .print-toolbar { display: none; }
            .report { width: 100%; padding: 0; border: 0; box-shadow: none; }
            .report-header { padding-bottom: 16px; }
            .brand-logo { width: 52px; height: 52px; flex-basis: 52px; }
            .brand-name { font-size: 21px; }
            .executive-title { margin-top: 18px; }
            .kpi { min-height: 78px; padding: 11px; }
            .kpi strong { font-size: 16px; }
            .section { margin-top: 20px; }
            .panel, .kpi, .section-heading { break-inside: avoid; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <a class="toolbar-button" href="admin.php?tab=relatorios&data_inicio=<?= urlencode($dataInicio) ?>&data_fim=<?= urlencode($dataFim) ?>">
            <i class="fa fa-arrow-left"></i> Voltar aos relatórios
        </a>
        <div class="toolbar-group">
            <button type="button" class="toolbar-button primary" onclick="window.print()">
                <i class="fa fa-print"></i> Imprimir ou salvar PDF
            </button>
        </div>
    </div>

    <main class="report">
        <header class="report-header">
            <div class="brand">
                <?php if ($mostrarLogo): ?>
                    <img class="brand-logo" src="<?= htmlspecialchars($logoPath) ?>" alt="">
                <?php endif; ?>
                <div>
                    <h2 class="brand-name"><?= htmlspecialchars($configGeral['nome_barbearia']) ?></h2>
                    <p class="brand-subtitle">Relatório gerencial consolidado</p>
                </div>
            </div>
            <div class="report-meta">
                <strong><?= htmlspecialchars($periodoFormatado) ?></strong>
                <span>Emitido em <?= date('d/m/Y \à\s H:i') ?></span>
            </div>
        </header>

        <div class="executive-title">
            <span>Visão executiva</span>
            <h1>Resultado do período</h1>
        </div>

        <div class="kpi-grid">
            <div class="kpi">
                <small>Receita total</small>
                <strong>R$ <?= number_format($resumoFinanceiro['receita_total'], 2, ',', '.') ?></strong>
                <em>Atendimentos, produtos e mensalidades</em>
            </div>
            <div class="kpi negative">
                <small>Custos totais</small>
                <strong>R$ <?= number_format($totalCustos, 2, ',', '.') ?></strong>
                <em>Comissões e despesas por competência</em>
            </div>
            <div class="kpi <?= $lucroLiquido >= 0 ? 'positive' : 'negative' ?>">
                <small>Resultado líquido</small>
                <strong>R$ <?= number_format($lucroLiquido, 2, ',', '.') ?></strong>
                <em>Receitas menos custos do período</em>
            </div>
            <div class="kpi">
                <small>Ticket por atendimento</small>
                <strong>R$ <?= number_format($resumoFinanceiro['ticket_medio'], 2, ',', '.') ?></strong>
                <em><?= count($agendamentosConcluidos) ?> atendimento(s) concluído(s)</em>
            </div>
        </div>

        <section class="section">
            <div class="section-heading">
                <h2>Demonstrativo financeiro</h2>
                <p>Regime de competência para despesas</p>
            </div>
            <div class="split">
                <div class="panel">
                    <div class="panel-title">Composição da receita</div>
                    <table>
                        <tbody>
                            <tr><td>Serviços líquidos</td><td class="money positive-text">R$ <?= number_format($resumoFinanceiro['receita_servicos'], 2, ',', '.') ?></td></tr>
                            <tr><td>Produtos vendidos</td><td class="money positive-text">R$ <?= number_format($resumoFinanceiro['receita_produtos'], 2, ',', '.') ?></td></tr>
                            <tr><td>Mensalidades de planos</td><td class="money positive-text">R$ <?= number_format($resumoFinanceiro['receita_planos'], 2, ',', '.') ?></td></tr>
                            <tr><td><strong>Receita total</strong></td><td class="money"><strong>R$ <?= number_format($resumoFinanceiro['receita_total'], 2, ',', '.') ?></strong></td></tr>
                            <tr><td style="color:#64748b;">Gorjetas recebidas <small>(100% da equipe — fora do resultado)</small></td><td class="money" style="color:#64748b;">R$ <?= number_format($relTotalGorjetas, 2, ',', '.') ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel">
                    <div class="panel-title">Custos e resultado</div>
                    <table>
                        <tbody>
                            <tr><td>Comissões geradas</td><td class="money negative-text">R$ <?= number_format($equipeRelatorio['total_comissoes'], 2, ',', '.') ?></td></tr>
                            <tr><td>Despesas operacionais</td><td class="money negative-text">R$ <?= number_format($despesasRelatorio['total'], 2, ',', '.') ?></td></tr>
                            <tr><td>Custo de produtos (CMV)</td><td class="money negative-text">R$ <?= number_format($relCmvProdutos, 2, ',', '.') ?></td></tr>
                            <tr><td>Descontos concedidos</td><td class="money">R$ <?= number_format($resumoFinanceiro['descontos'], 2, ',', '.') ?></td></tr>
                            <tr><td><strong>Resultado líquido</strong></td><td class="money <?= $lucroLiquido >= 0 ? 'positive-text' : 'negative-text' ?>"><strong>R$ <?= number_format($lucroLiquido, 2, ',', '.') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel" style="margin-top: 16px;">
                <div class="panel-title">Entradas por forma de pagamento</div>
                <table>
                    <tbody>
                        <?php
                        $relFormasLabels = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Cartão de débito', 'credito' => 'Cartão de crédito', 'outro' => 'Outro', 'nao_informado' => 'Não informado'];
                        $relTotalCaixa = array_sum($relCaixaPorForma);
                        $algumaForma = false;
                        foreach ($relFormasLabels as $fk => $flabel):
                            if ($relCaixaPorForma[$fk] <= 0) continue;
                            $algumaForma = true;
                            $fpct = $relTotalCaixa > 0 ? ($relCaixaPorForma[$fk] / $relTotalCaixa) * 100 : 0;
                        ?>
                            <tr><td><?= $flabel ?> <small style="color:#94a3b8;">(<?= number_format($fpct, 0) ?>%)</small></td><td class="money">R$ <?= number_format($relCaixaPorForma[$fk], 2, ',', '.') ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$algumaForma): ?>
                            <tr><td colspan="2" style="color:#94a3b8;">Sem registros de forma de pagamento no período (informado a partir da comanda).</td></tr>
                        <?php else: ?>
                            <tr><td><strong>Total recebido</strong></td><td class="money"><strong>R$ <?= number_format($relTotalCaixa, 2, ',', '.') ?></strong></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($resumoFinanceiro['pagamentos_estimados'] > 0): ?>
                <div class="data-note">
                    <?= (int)$resumoFinanceiro['pagamentos_estimados'] ?> mensalidade(s) de bases anteriores ao histórico detalhado foram estimadas pelo valor atual do plano.
                </div>
            <?php endif; ?>
        </section>

        <section class="section">
            <div class="section-heading">
                <h2>Operação e agenda</h2>
                <p>Ocupação calculada pela duração real dos serviços e combos</p>
            </div>
            <div class="split">
                <div class="panel">
                    <div class="panel-title">Situação dos agendamentos</div>
                    <div class="status-grid">
                        <div class="status-item"><strong><?= (int)($contagemStatus['concluido'] ?? 0) ?></strong><span>Concluídos</span></div>
                        <div class="status-item"><strong><?= (int)($contagemStatus['aprovado'] ?? 0) ?></strong><span>Aprovados</span></div>
                        <div class="status-item"><strong><?= (int)($contagemStatus['cancelado'] ?? 0) ?></strong><span>Cancelados</span></div>
                        <div class="status-item"><strong><?= number_format($taxaConclusao, 1, ',', '.') ?>%</strong><span>Conclusão</span></div>
                    </div>
                    <table>
                        <tbody>
                            <tr><td>Ocupação média da equipe</td><td class="money"><?= number_format($ocupacaoMedia, 1, ',', '.') ?>%</td></tr>
                            <tr><td>Aguardando pagamento</td><td class="money"><?= (int)($contagemStatus['aguardando_pagamento'] ?? 0) ?></td></tr>
                            <tr><td>Pendentes</td><td class="money"><?= (int)($contagemStatus['pendente'] ?? 0) ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel">
                    <div class="panel-title">Serviços e combos mais realizados</div>
                    <table>
                        <thead><tr><th>Item</th><th class="money">Realizações</th></tr></thead>
                        <tbody>
                            <?php foreach ($rankingServicos as $id => $quantidade):
                                $item = $servicosArr[$id] ?? $combosArr[$id] ?? null;
                                if (!$item) continue;
                            ?>
                                <tr><td><?= htmlspecialchars($item['nome']) ?></td><td class="money"><?= (int)$quantidade ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$rankingServicos): ?><tr><td colspan="2" class="empty">Sem realizações concluídas.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="section-heading">
                <h2>Desempenho e comissões da equipe</h2>
                <p>Regras individuais e de assinatura aplicadas</p>
            </div>
            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Profissional</th>
                            <th>Atendimentos</th>
                            <th class="money">Faturamento</th>
                            <th class="money">Comissão bruta</th>
                            <th class="money">Vales</th>
                            <th class="money">Líquido estimado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipeRelatorio['profissionais'] as $profissional): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($profissional['nome']) ?></strong><br>
                                    <small><?= htmlspecialchars($profissional['regra_assinatura']) ?></small>
                                </td>
                                <td><?= (int)$profissional['atendimentos'] ?></td>
                                <td class="money">R$ <?= number_format($profissional['faturamento_total'], 2, ',', '.') ?></td>
                                <td class="money">R$ <?= number_format($profissional['comissao_bruta'], 2, ',', '.') ?></td>
                                <td class="money">R$ <?= number_format($profissional['vales'], 2, ',', '.') ?></td>
                                <td class="money positive-text">R$ <?= number_format($profissional['comissao_liquida'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$equipeRelatorio['profissionais']): ?><tr><td colspan="6" class="empty">Nenhum profissional cadastrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-heading">
                <h2>Assinaturas</h2>
                <p>Stripe e ativações manuais</p>
            </div>
            <div class="kpi-grid">
                <div class="kpi"><small>Assinantes ativos</small><strong><?= $assinantesAtivos ?></strong><em>Inclui cancelamento ao fim do ciclo</em></div>
                <div class="kpi"><small>MRR atual</small><strong>R$ <?= number_format($mrrAtual, 2, ',', '.') ?></strong><em>Valor mensal contratado</em></div>
                <div class="kpi"><small>Adesões no período</small><strong><?= $adesoesPeriodo ?></strong><em>Pagamentos identificados como adesão</em></div>
                <div class="kpi"><small>Mensalidades recebidas</small><strong><?= count($resumoFinanceiro['pagamentos_assinatura']) ?></strong><em>R$ <?= number_format($resumoFinanceiro['receita_planos'], 2, ',', '.') ?></em></div>
            </div>
            <?php if ($resumoFinanceiro['pagamentos_assinatura']): ?>
                <div class="panel" style="margin-top: 12px;">
                    <table>
                        <thead><tr><th>Data</th><th>Cliente</th><th>Plano</th><th>Origem</th><th class="money">Valor</th></tr></thead>
                        <tbody>
                            <?php foreach ($resumoFinanceiro['pagamentos_assinatura'] as $pagamento): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($pagamento['data_pagamento'])) ?></td>
                                    <td><?= htmlspecialchars($clientesArr[$pagamento['cliente_id']]['nome'] ?? 'Cliente') ?></td>
                                    <td><?= htmlspecialchars($planosArr[$pagamento['plano_id']]['nome'] ?? 'Plano removido') ?></td>
                                    <td><?= htmlspecialchars(ucfirst($pagamento['gateway'] ?? 'manual')) ?><?= !empty($pagamento['estimado']) ? ' (estimado)' : '' ?></td>
                                    <td class="money">R$ <?= number_format((float)$pagamento['valor'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="section">
            <div class="section-heading">
                <h2>Clientes e experiência</h2>
                <p>Comportamento dentro do período selecionado</p>
            </div>
            <div class="split">
                <div class="panel">
                    <div class="panel-title">Indicadores de relacionamento</div>
                    <table>
                        <tbody>
                            <tr><td>Novos clientes atendidos</td><td class="money"><?= (int)$novosVsRecorrentes['novos'] ?></td></tr>
                            <tr><td>Clientes recorrentes</td><td class="money"><?= (int)$novosVsRecorrentes['recorrentes'] ?></td></tr>
                            <tr><td>Intervalo médio de retorno</td><td class="money"><?= $frequenciaMedia === 'N/D' ? 'N/D' : $frequenciaMedia . ' dias' ?></td></tr>
                            <tr><td>Avaliação média</td><td class="money"><?= $avaliacoesPeriodo ? number_format($mediaAvaliacoes, 1, ',', '.') . ' / 5' : 'N/D' ?></td></tr>
                            <tr><td>Clientes em risco na base</td><td class="money"><?= count($clientesEmRisco) ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel">
                    <div class="panel-title">Maiores gastos em atendimentos</div>
                    <table>
                        <thead><tr><th>Cliente</th><th class="money">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($topClientes as $cliente => $valor): ?>
                                <tr><td><?= htmlspecialchars($cliente) ?></td><td class="money">R$ <?= number_format($valor, 2, ',', '.') ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$topClientes): ?><tr><td colspan="2" class="empty">Sem dados no período.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if ($relProdutosVendidos || $despesasPorCategoria): ?>
            <section class="section">
                <div class="section-heading">
                    <h2>Produtos e despesas por categoria</h2>
                    <p>Margem dos produtos vendidos e composição das saídas</p>
                </div>
                <div class="split">
                    <div class="panel">
                        <div class="panel-title">Vendas de produtos (margem)</div>
                        <table>
                            <thead><tr><th>Produto</th><th class="money">Qtd</th><th class="money">Receita</th><th class="money">Margem</th></tr></thead>
                            <tbody>
                                <?php foreach ($relProdutosVendidos as $nomeProd => $info): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($nomeProd) ?></td>
                                        <td class="money"><?= (int)$info['quantidade'] ?></td>
                                        <td class="money">R$ <?= number_format($info['receita'], 2, ',', '.') ?></td>
                                        <td class="money">R$ <?= number_format($info['margem'], 2, ',', '.') ?> <small>(<?= number_format($info['margem_perc'], 0) ?>%)</small></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$relProdutosVendidos): ?><tr><td colspan="4" class="empty">Sem vendas de produtos.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel">
                        <div class="panel-title">Despesas por categoria</div>
                        <table>
                            <thead><tr><th>Categoria</th><th class="money">Lanç.</th><th class="money">Valor</th></tr></thead>
                            <tbody>
                                <?php foreach ($despesasPorCategoria as $cat => $info): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($cat) ?></td>
                                        <td class="money"><?= (int)$info['quantidade'] ?></td>
                                        <td class="money">R$ <?= number_format($info['total'], 2, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$despesasPorCategoria): ?><tr><td colspan="3" class="empty">Sem despesas lançadas.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($despesasRelatorio['itens']): ?>
            <section class="section">
                <div class="section-heading">
                    <h2>Despesas do período</h2>
                    <p><?= count($despesasRelatorio['itens']) ?> lançamento(s) por data de vencimento</p>
                </div>
                <div class="panel">
                    <table>
                        <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th>Status</th><th class="money">Valor</th></tr></thead>
                        <tbody>
                            <?php foreach ($despesasRelatorio['itens'] as $despesa): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($despesa['data_competencia'])) ?></td>
                                    <td><?= htmlspecialchars($despesa['descricao'] ?? 'Despesa') ?></td>
                                    <td><?= htmlspecialchars($despesa['categoria'] ?? 'Outros') ?></td>
                                    <td><?= htmlspecialchars(ucfirst($despesa['status'] ?? 'pendente')) ?></td>
                                    <td class="money">R$ <?= number_format((float)$despesa['valor'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <footer class="report-footer">
            <span><?= htmlspecialchars($configGeral['nome_barbearia']) ?> · Documento gerencial de uso interno</span>
            <span>Período <?= htmlspecialchars($periodoFormatado) ?></span>
        </footer>
    </main>
</body>
</html>

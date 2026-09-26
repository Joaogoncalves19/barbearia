<?php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

// --- SEGURANÇA: exige admin OU barbeiro autenticado ---
$ehAdmin = !empty($_SESSION['loggedin']);
$ehBarbeiro = !empty($_SESSION['barbeiro_loggedin']);
if (!$ehAdmin && !$ehBarbeiro) {
    header('Location: login.php');
    exit;
}

// --- CARREGAMENTO DE DADOS (nomes de tabela corretos, sem .txt) ---
$configGeral = carregarConfigGeral();
$barbeirosArr = lerDados('barbeiros', ['id', 'nome', 'foto']);
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor']);
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor']);
$planosArr = lerDados('planos', ['id', 'nome', 'valor']);

if (function_exists('garantirColunasComanda')) garantirColunasComanda();
$keys_agendamentos = ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'gorjeta', 'forma_pagamento'];
$agendamentosArr = lerDados('agendamentos', $keys_agendamentos);

// --- FILTRAGEM ---
$data_filtro = $_GET['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_filtro)) {
    $data_filtro = date('Y-m-d');
}
$data_formatada = date('d/m/Y', strtotime($data_filtro));
$dias_semana_pt = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$dia_semana_txt = $dias_semana_pt[(int)date('w', strtotime($data_filtro))];

// Barbeiro só vê a própria agenda; admin pode filtrar por profissional (opcional).
if ($ehBarbeiro && !$ehAdmin) {
    $filtro_barbeiro = $_SESSION['barbeiro_id'] ?? '';
} else {
    $filtro_barbeiro = $_GET['barbeiro_id'] ?? '';
}

$agendamentosDoDia = array_filter($agendamentosArr, function ($ag) use ($data_filtro, $filtro_barbeiro) {
    if (($ag['data'] ?? '') !== $data_filtro) return false;
    if (in_array($ag['status'] ?? '', ['cancelado', 'cancelado_pelo_cliente'], true)) return false;
    if ($filtro_barbeiro !== '' && ($ag['barbeiro_id'] ?? '') !== $filtro_barbeiro) return false;
    return true;
});

// Helper: valor líquido do atendimento (serviços/combos + produtos - desconto).
if (!function_exists('valorAgendaDia')) {
    function valorAgendaDia($ag, $servicosArr, $combosArr) {
        $t = 0;
        foreach (explode(',', $ag['servicos_ids'] ?? '') as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid])) $t += (float)$servicosArr[$sid]['valor'];
            elseif (isset($combosArr[$sid])) $t += (float)$combosArr[$sid]['valor'];
        }
        $pj = json_decode($ag['produtos_vendidos'] ?? '', true);
        if (is_array($pj)) foreach ($pj as $p) $t += (float)($p['valor'] ?? 0);
        return max(0, $t - (float)($ag['desconto_aplicado'] ?? 0));
    }
}

// Agrupa por barbeiro
$agendamentosPorBarbeiro = [];
foreach ($agendamentosDoDia as $ag) {
    $agendamentosPorBarbeiro[$ag['barbeiro_id']][] = $ag;
}
uksort($agendamentosPorBarbeiro, function ($a_id, $b_id) use ($barbeirosArr) {
    return strnatcasecmp($barbeirosArr[$a_id]['nome'] ?? 'N/A', $barbeirosArr[$b_id]['nome'] ?? 'N/A');
});

// --- RESUMO DO DIA (geral) ---
$resumoDia = ['total' => 0, 'valor' => 0, 'gorjetas' => 0, 'pendente' => 0, 'aprovado' => 0, 'concluido' => 0, 'aguardando_pagamento' => 0];
foreach ($agendamentosDoDia as $ag) {
    $resumoDia['total']++;
    $resumoDia['valor'] += valorAgendaDia($ag, $servicosArr, $combosArr);
    $resumoDia['gorjetas'] += max(0, (float)($ag['gorjeta'] ?? 0));
    $st = $ag['status'] ?? '';
    if (isset($resumoDia[$st])) $resumoDia[$st]++;
}

// Rótulos de forma de pagamento
$formasPagamentoLabels = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Débito', 'credito' => 'Crédito', 'outro' => 'Outro'];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda do Dia - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #0f172a;
            --text-main: #334155;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --accent-color: #3b82f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            font-size: 13px;
            margin: 0;
            padding: 40px 20px;
        }

        /* --- Estilos de Tela (Visual Premium) --- */
        .page-container {
            width: 100%;
            max-width: 21cm; /* Padrão A4 */
            margin: 0 auto;
            background: var(--bg-card);
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid var(--border-color);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 25px;
            margin-bottom: 35px;
        }

        .brand-info h1 {
            margin: 0 0 5px 0;
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-color);
            letter-spacing: -0.5px;
        }

        .brand-info p {
            margin: 2px 0;
            color: var(--text-muted);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .report-meta {
            text-align: right;
        }

        .report-meta h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .date-badge {
            display: inline-block;
            background: #eff6ff;
            color: var(--accent-color);
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            border: 1px solid #bfdbfe;
        }

        .barbeiro-section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }

        .barbeiro-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f1f5f9;
            padding: 12px 20px;
            border-radius: 10px 10px 0 0;
            border: 1px solid var(--border-color);
            border-bottom: none;
        }

        .barbeiro-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .barbeiro-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background-color: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 14px;
        }

        .barbeiro-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .atendimentos-count {
            background: #e2e8f0;
            color: #475569;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border-color);
        }

        .modern-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
        }

        .modern-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }

        .modern-table tr:last-child td {
            border-bottom: none;
        }

        .modern-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .hora-cell {
            font-weight: 800;
            font-size: 15px;
            color: var(--primary-color);
            text-align: center;
        }

        .cliente-name {
            font-weight: 700;
            font-size: 14px;
            color: var(--primary-color);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .cliente-phone {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .servicos-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .servicos-list li {
            position: relative;
            padding-left: 12px;
            margin-bottom: 3px;
            color: #475569;
        }
        
        .servicos-list li::before {
            content: "•";
            position: absolute;
            left: 0;
            color: var(--accent-color);
            font-weight: bold;
        }

        /* Badges e Etiquetas */
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-vip { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-adesao { background: #dcfce3; color: #166534; border: 1px solid #bbf7d0; }

        .status-badge { width: 100%; padding: 6px; text-align: center; border-radius: 6px; }
        .status-pendente { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .status-aprovado { background: #dcfce3; color: #166534; border: 1px solid #bbf7d0; }
        .status-concluido { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .obs-box {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            color: #b45309;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .produtos-lista {
            font-size: 11px;
            color: #0f766e;
            background: #f0fdfa;
            border: 1px solid #ccfbf1;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .financeiro-valor {
            font-weight: 800;
            font-size: 14px;
            color: var(--primary-color);
        }

        .financeiro-desc {
            font-size: 11px;
            color: #ef4444;
            font-weight: 600;
            margin-top: 2px;
        }

        .financeiro-total {
            font-size: 10px;
            color: var(--text-muted);
            text-decoration: line-through;
        }

        .table-footer td {
            background: #f8fafc;
            font-weight: 700;
            font-size: 13px;
            color: var(--primary-color);
            border-top: 2px solid var(--border-color);
        }

        /* Botão de Imprimir (Centralizado no Topo) */
        .print-wrapper {
            text-align: center;
            margin-bottom: 30px;
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--accent-color);
            color: white;
            padding: 12px 28px;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            font-size: 15px;
            font-weight: 700;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
            transition: all 0.2s;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            background: #2563eb;
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.4);
        }

        .footer-note {
            text-align: center;
            margin-top: 40px;
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Resumo do dia */
        .day-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 32px; }
        .summary-card { background: #f8fafc; border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; }
        .summary-card small { display: block; color: var(--text-muted); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .summary-card strong { display: block; font-size: 20px; font-weight: 800; color: var(--primary-color); margin-top: 4px; }
        .summary-card.accent strong { color: #10b981; }
        .summary-card.tips strong { color: #7c3aed; }

        .pay-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #0369a1; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 6px; margin-top: 4px; }
        .tip-badge { display: inline-block; font-size: 10px; font-weight: 700; color: #7c3aed; background: #f5f3ff; border: 1px solid #e9d5ff; padding: 2px 7px; border-radius: 6px; margin-top: 4px; }
        .filter-note { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

        /* --- Estilos de Impressão (Print) --- */
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                border: none;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }
            .print-wrapper {
                display: none;
            }
            .barbeiro-section {
                page-break-inside: avoid;
            }
            .modern-table th {
                background-color: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                color: #000;
            }
            .barbeiro-header {
                background-color: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                border-bottom: 1px solid #ccc;
            }
            .badge, .status-badge, .obs-box, .produtos-lista, .pay-badge, .tip-badge {
                border: 1px solid #ccc;
                background: transparent !important;
                color: #000 !important;
            }
            .day-summary { display: grid !important; }
            .summary-card { background: transparent !important; border: 1px solid #ccc; -webkit-print-color-adjust: exact; }
            .financeiro-desc {
                color: #000 !important;
            }
        }
    </style>
</head>
<body>

    <div class="print-wrapper">
        <button class="btn-print" onclick="window.print()">
            <i class="fa fa-print"></i> Imprimir Agenda
        </button>
    </div>

    <div class="page-container">
        
        <header class="header">
            <div class="brand-info">
                <h1><?= htmlspecialchars($configGeral['nome_barbearia']) ?></h1>
                <p><i class="fa fa-map-marker-alt"></i> <?= htmlspecialchars($configGeral['endereco']) ?></p>
                <p><i class="fa fa-phone-alt"></i> <?= htmlspecialchars($configGeral['telefone_contato']) ?></p>
            </div>
            <div class="report-meta">
                <h2>Agenda do Dia</h2>
                <div class="date-badge">
                    <i class="fa fa-calendar-day"></i> <?= htmlspecialchars($dia_semana_txt) ?>, <?= htmlspecialchars($data_formatada) ?>
                </div>
                <?php if ($filtro_barbeiro !== '' && isset($barbeirosArr[$filtro_barbeiro])): ?>
                    <div class="filter-note"><i class="fa fa-user-tie"></i> <?= htmlspecialchars($barbeirosArr[$filtro_barbeiro]['nome']) ?></div>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($agendamentosPorBarbeiro)): ?>
        <div class="day-summary">
            <div class="summary-card"><small>Atendimentos</small><strong><?= (int)$resumoDia['total'] ?></strong></div>
            <div class="summary-card"><small>Concluídos</small><strong><?= (int)$resumoDia['concluido'] ?></strong></div>
            <div class="summary-card"><small>Aprovados</small><strong><?= (int)$resumoDia['aprovado'] ?></strong></div>
            <?php if ($resumoDia['pendente'] > 0 || $resumoDia['aguardando_pagamento'] > 0): ?>
                <div class="summary-card"><small>A confirmar</small><strong><?= (int)($resumoDia['pendente'] + $resumoDia['aguardando_pagamento']) ?></strong></div>
            <?php endif; ?>
            <div class="summary-card accent"><small>Total estimado</small><strong>R$ <?= number_format($resumoDia['valor'], 2, ',', '.') ?></strong></div>
            <?php if ($resumoDia['gorjetas'] > 0): ?>
                <div class="summary-card tips"><small>Gorjetas</small><strong>R$ <?= number_format($resumoDia['gorjetas'], 2, ',', '.') ?></strong></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($agendamentosPorBarbeiro)): ?>
            <div style="text-align:center; padding: 60px 20px; background: #f8fafc; border-radius: 12px; border: 2px dashed #cbd5e1;">
                <i class="fa fa-calendar-times" style="font-size: 40px; color: #94a3b8; margin-bottom: 15px;"></i>
                <h3 style="margin: 0; color: #475569;">Nenhum agendamento encontrado</h3>
                <p style="color: #64748b; margin-top: 5px;">Não há reservas confirmadas ou pendentes para esta data.</p>
            </div>
        <?php else: ?>
            
            <?php foreach ($agendamentosPorBarbeiro as $barbeiro_id => $agendamentos): ?>
                <?php
                // Ordena os agendamentos por hora
                usort($agendamentos, fn($a, $b) => strcmp($a['hora'], $b['hora']));
                $barbeiro = $barbeirosArr[$barbeiro_id] ?? ['nome' => 'N/A', 'foto' => ''];
                
                // Totais do Barbeiro
                $totalAgendamentos = count($agendamentos);
                $totalEstimado = 0;
                $totalGorjetasBarbeiro = 0;
                $fotoBarbeiro = (!empty(trim($barbeiro['foto'] ?? '')) && file_exists(trim($barbeiro['foto']))) ? trim($barbeiro['foto']) : '';
                ?>

                <section class="barbeiro-section">

                    <div class="barbeiro-header">
                        <div class="barbeiro-profile">
                            <?php if($fotoBarbeiro !== ''): ?>
                                <img src="<?= htmlspecialchars($fotoBarbeiro) ?>" alt="Foto" class="barbeiro-avatar">
                            <?php else: ?>
                                <div class="barbeiro-avatar"><i class="fa fa-user"></i></div>
                            <?php endif; ?>
                            <span class="barbeiro-name"><?= htmlspecialchars($barbeiro['nome']) ?></span>
                        </div>
                        <span class="atendimentos-count"><?= $totalAgendamentos ?> Atendimento(s)</span>
                    </div>
                    
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th style="width: 80px; text-align: center;">Hora</th>
                                <th style="width: 25%;">Cliente</th>
                                <th style="width: 30%;">Serviços</th>
                                <th style="width: 20%;">Detalhes Adicionais</th>
                                <th style="width: 15%;">Financeiro</th>
                                <th style="width: 100px; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agendamentos as $ag): 
                                // Calcula valores
                                $valorTotal = 0;
                                $servicosTxt = [];
                                $servicosIds = explode(',', $ag['servicos_ids']);
                                
                                foreach ($servicosIds as $sid) {
                                    $sid = trim($sid);
                                    if (isset($servicosArr[$sid])) {
                                        $servicosTxt[] = $servicosArr[$sid]['nome'];
                                        $valorTotal += (float)$servicosArr[$sid]['valor'];
                                    } elseif (isset($combosArr[$sid])) {
                                        $servicosTxt[] = $combosArr[$sid]['nome'] . " <span style='color:#a855f7;font-size:10px;'>(Combo)</span>";
                                        $valorTotal += (float)$combosArr[$sid]['valor'];
                                    } else {
                                        $servicosTxt[] = "<em style='color:#ef4444;'>Item Removido</em>";
                                    }
                                }
                                
                                // Produtos
                                $produtosTxt = [];
                                if (!empty($ag['produtos_vendidos'])) {
                                    $prods = json_decode($ag['produtos_vendidos'], true);
                                    if (is_array($prods)) {
                                        foreach ($prods as $p) {
                                            $produtosTxt[] = $p['nome'];
                                            $valorTotal += (float)$p['valor'];
                                        }
                                    }
                                }
                                
                                $desconto = (float)($ag['desconto_aplicado'] ?? 0);
                                $valorFinal = $valorTotal - $desconto;
                                $totalEstimado += $valorFinal;
                                $totalGorjetasBarbeiro += max(0, (float)($ag['gorjeta'] ?? 0));
                                
                                // Tags
                                $isVip = ($ag['tipo_desconto'] ?? '') === 'assinatura_vip';
                                $isAdesao = ($ag['tipo_desconto'] ?? '') === 'adesao_plano';
                            ?>
                                <tr>
                                    <td class="hora-cell"><?= htmlspecialchars($ag['hora']) ?></td>
                                    <td>
                                        <div class="cliente-name">
                                            <?= htmlspecialchars($ag['nome']) ?>
                                            <?php if ($isVip): ?><span class="badge badge-vip">Assinatura</span><?php endif; ?>
                                            <?php if ($isAdesao): ?><span class="badge badge-adesao">Adesão</span><?php endif; ?>
                                        </div>
                                        <div class="cliente-phone">
                                            <i class="fa fa-phone"></i> <?= htmlspecialchars($ag['telefone']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <ul class="servicos-list">
                                            <?php foreach($servicosTxt as $s): ?>
                                                <li><?= $s // Permitindo HTML seguro para o "(Combo)" gerado acima ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                    <td>
                                        <?php if (!empty($ag['observacoes'])): ?>
                                            <div class="obs-box">
                                                <i class="fa fa-comment-dots" style="margin-right:4px;"></i> 
                                                <?= htmlspecialchars($ag['observacoes']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($produtosTxt)): ?>
                                            <div class="produtos-lista">
                                                <i class="fa fa-shopping-bag" style="margin-right:4px;"></i>
                                                <strong>Prod:</strong> <?= implode(', ', $produtosTxt) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="financeiro-valor">R$ <?= number_format($valorFinal, 2, ',', '.') ?></div>
                                        <?php if ($desconto > 0): ?>
                                            <div class="financeiro-desc">Desc: -R$ <?= number_format($desconto, 2, ',', '.') ?></div>
                                            <div class="financeiro-total">Total: R$ <?= number_format($valorTotal, 2, ',', '.') ?></div>
                                        <?php endif; ?>
                                        <?php $gorj = max(0, (float)($ag['gorjeta'] ?? 0)); $fp = $ag['forma_pagamento'] ?? ''; ?>
                                        <?php if ($fp !== '' && isset($formasPagamentoLabels[$fp])): ?>
                                            <div class="pay-badge"><i class="fa fa-money-bill-wave"></i> <?= htmlspecialchars($formasPagamentoLabels[$fp]) ?></div>
                                        <?php endif; ?>
                                        <?php if ($gorj > 0): ?>
                                            <div class="tip-badge">Gorjeta: R$ <?= number_format($gorj, 2, ',', '.') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="badge status-badge status-<?= htmlspecialchars($ag['status']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $ag['status'])) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-footer">
                                <td colspan="4" style="text-align: right; text-transform: uppercase;">Total Estimado (<?= htmlspecialchars($barbeiro['nome']) ?>):</td>
                                <td colspan="2" style="font-size: 15px; color: #10b981;">
                                    R$ <?= number_format($totalEstimado, 2, ',', '.') ?>
                                    <?php if ($totalGorjetasBarbeiro > 0): ?>
                                        <div style="font-size: 11px; color: #7c3aed; font-weight: 600;">+ R$ <?= number_format($totalGorjetasBarbeiro, 2, ',', '.') ?> em gorjetas</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            <?php endforeach; ?>
            
            <div class="footer-note">
                Relatório gerado automaticamente pelo sistema em <strong><?= date('d/m/Y \à\s H:i:s') ?></strong>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
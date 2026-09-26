<?php
// admin_tabs/agendamentos.php

$view = $_GET['view'] ?? 'today';
$view_mode = $_GET['view_mode'] ?? 'list';
$filtro_data_agendamento = $_GET['filtro_data'] ?? date('Y-m-d');
$filtro_horario = $_GET['filtro_horario'] ?? '';
$filtro_barbeiro = $_GET['filtro_barbeiro'] ?? '';
$filtro_status = $_GET['filtro_status'] ?? '';
$currentPage = (int)($_GET['page'] ?? 1);

// Variáveis globais vindas do painel
$agendamentosPaginados = $agendamentosPaginados ?? [];
$agendamentosAgrupadosPorBarbeiro = $agendamentosAgrupadosPorBarbeiro ?? [];
$barbeirosArr = $barbeirosArr ?? [];
$servicosArr = $servicosArr ?? [];
$combosArr = $combosArr ?? [];
$planosArr = $planosArr ?? [];
$clientesArr = $clientesArr ?? [];
$assinaturasClientesArr = $assinaturasClientesArr ?? [];
$totalPages = $totalPages ?? 1;

// FILTRO DE SEGURANÇA E LIMPEZA
$agendamentosPaginados = array_filter($agendamentosPaginados, fn($a) => $a['status'] !== 'aguardando_pagamento');

if ($view_mode === 'list') {
    usort($agendamentosPaginados, function($a, $b) {
        return strtotime($a['hora']) - strtotime($b['hora']);
    });
}

foreach($agendamentosAgrupadosPorBarbeiro as $bid => $ags) {
    $agendamentosAgrupadosPorBarbeiro[$bid] = array_filter($ags, fn($a) => $a['status'] !== 'aguardando_pagamento');
    usort($agendamentosAgrupadosPorBarbeiro[$bid], function($a, $b) {
        return strtotime($a['hora']) - strtotime($b['hora']);
    });
}

// Links para os botões de visão
$base_query_params = ['tab' => 'agendamentos', 'view' => $view, 'filtro_barbeiro' => $filtro_barbeiro, 'filtro_status' => $filtro_status];
$link_list_view = 'admin.php?' . http_build_query(array_merge($base_query_params, ['view_mode' => 'list', 'filtro_data' => $filtro_data_agendamento]));
$link_board_view = 'admin.php?' . http_build_query(array_merge($base_query_params, ['view_mode' => 'board', 'filtro_data' => $filtro_data_agendamento]));
$link_registro_view = 'admin.php?' . http_build_query(array_merge($base_query_params, ['view_mode' => 'registro', 'filtro_data' => $filtro_data_agendamento]));

$data_para_imprimir = !empty($filtro_data_agendamento) ? $filtro_data_agendamento : date('Y-m-d');
$link_imprimir_agenda = "imprimir_agenda_dia.php?data=" . htmlspecialchars($data_para_imprimir);

if (!function_exists('findClienteId')) {
    function findClienteId($ag, $clientesArr) {
        if (isset($ag['cliente_id_encontrado']) && $ag['cliente_id_encontrado']) return $ag['cliente_id_encontrado'];
        foreach ($clientesArr as $cid => $c) {
            if ($c['email'] === $ag['email'] || (isset($c['telefone']) && isset($ag['telefone']) && limparTelefone($c['telefone']) === limparTelefone($ag['telefone']))) return $cid;
        }
        return null;
    }
}

if (!function_exists('renderAgendamentoDetalhes')) {
    function renderAgendamentoDetalhes($ag, $servicosArr, $planosArr, $combosArr, $assinaturasClientesArr = []) {
        $servicosIds = isset($ag['servicos_ids']) ? explode(',', $ag['servicos_ids']) : [];
        $servicoNomes = [];

        foreach($servicosIds as $sid) {
            $sid = trim($sid);
            if(isset($servicosArr[$sid])) {
                $servicoNomes[] = $servicosArr[$sid]['nome'];
            } elseif(isset($combosArr[$sid])) {
                $combo = $combosArr[$sid];
                $subServicosNomes = [];
                if (!empty($combo['servicos_ids'])) {
                    foreach (explode(',', $combo['servicos_ids']) as $subId) {
                        $subId = trim($subId);
                        if (isset($servicosArr[$subId])) $subServicosNomes[] = $servicosArr[$subId]['nome'];
                    }
                }
                if (!empty($subServicosNomes)) {
                    $servicoNomes[] = $combo['nome'] . " (Combo: " . implode(' + ', $subServicosNomes) . ")";
                } else {
                    $servicoNomes[] = $combo['nome'] . " (Combo)";
                }
            } else {
                $servicoNomes[] = '?';
            }
        }

        $desconto_valor = (float) ($ag['desconto_aplicado'] ?? 0);
        $tipo_desconto = $ag['tipo_desconto'] ?? '';
        $observacoes = $ag['observacoes'] ?? '';
        $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
        $plano_provisorio_id = $ag['plano_provisorio'] ?? '';

        echo "<div class='ag-servicos-lista'>" . htmlspecialchars(implode(', ', $servicoNomes)) . "</div>";

        if ($tipo_desconto === 'adesao_plano' && !empty($plano_provisorio_id)) {
            $nomePlano = $planosArr[$plano_provisorio_id]['nome'] ?? 'Plano';
            echo "<div class='ag-pill pill-purple'><i class='fa fa-crown'></i> Adesão: {$nomePlano}</div>";
        }
        elseif ($tipo_desconto === 'assinatura_vip' || $tipo_desconto === 'assinatura' || $tipo_desconto === 'plano') {
            $nomePlanoAtivo = 'Plano';
            $cliente_id = $ag['cliente_id_encontrado'] ?? $ag['cliente_id'] ?? null;
            if ($cliente_id && !empty($assinaturasClientesArr)) {
                foreach ($assinaturasClientesArr as $ass) {
                    if ($ass['cliente_id'] == $cliente_id && $ass['status'] === 'ativo') {
                        $plano_id = $ass['plano_id'];
                        if (isset($planosArr[$plano_id])) $nomePlanoAtivo = $planosArr[$plano_id]['nome'];
                        break;
                    }
                }
            }
            echo "<div class='ag-pill pill-green'><i class='fa fa-check-circle'></i> Barbearia por assinatura: {$nomePlanoAtivo}</div>";
        }

        if (!empty($produtos_vendidos)) {
            echo "<div class='ag-produtos-lista'><strong><i class='fa fa-box-open'></i> Produtos:</strong><ul>";
            foreach($produtos_vendidos as $p) echo "<li>" . htmlspecialchars($p['nome']) . " (+ R$" . number_format($p['valor'], 2, ',', '.') . ")</li>";
            echo "</ul></div>";
        }

        if ($desconto_valor > 0 && $tipo_desconto !== 'adesao_plano' && $tipo_desconto !== 'assinatura_vip' && $tipo_desconto !== 'assinatura' && $tipo_desconto !== 'plano') {
            $icon = 'fa-tag';
            if ($tipo_desconto === 'fidelidade') $icon = 'fa-star';
            if ($tipo_desconto === 'aniversario') $icon = 'fa-birthday-cake';
            if ($tipo_desconto === 'voucher') $icon = 'fa-gift';
            if ($tipo_desconto === 'indicacao') $icon = 'fa-user-plus';
            echo "<div class='ag-pill pill-emerald'><i class='fa {$icon}'></i> " . ucfirst($tipo_desconto) . ": -R$" . number_format($desconto_valor, 2, ',', '.') . "</div>";
        }

        if (!empty($observacoes)) echo "<div class='ag-observacoes'><i class='fa fa-comment-dots'></i> " . htmlspecialchars($observacoes) . "</div>";
    }
}

if (!function_exists('renderAgendamentoActions')) {
    function renderAgendamentoActions($ag) {
        $csrf_token = generate_csrf_token();
        ?>
        <div class="ag-actions">
            <?php if ($ag['status'] === 'aprovado'): ?>
                <button type="button" class="ag-btn-icon ag-btn-success" data-modal-target="#modal-comanda" data-type="comanda" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Concluir (abrir comanda)"><i class="fa fa-check"></i></button>

                <button class="ag-btn-icon ag-btn-info" data-modal-target="#modal-add-produto" data-id="<?= htmlspecialchars($ag['id']) ?>" title="Vender Produto"><i class="fa fa-shopping-bag"></i></button>
                <button class="ag-btn-icon ag-btn-secondary reagendar-btn" data-modal-target="#modal-reagendamento" data-id="<?= htmlspecialchars($ag['id']) ?>" data-barbeiro-id="<?= htmlspecialchars($ag['barbeiro_id']) ?>" data-servicos-ids="<?= htmlspecialchars($ag['servicos_ids']) ?>" title="Reagendar"><i class="fa fa-calendar-alt"></i></button>

                <a href="?action=cancelar&id=<?= $ag['id'] ?>&csrf_token=<?= $csrf_token ?>" class="ag-btn-icon ag-btn-danger" onclick="return confirmActionOverlay('Cancelar este agendamento?', this)" title="Cancelar"><i class="fa fa-times"></i></a>
            <?php endif; ?>

            <a href="imprimir_comprovativo_cliente.php?id=<?= $ag['id'] ?>" target="_blank" class="ag-btn-icon" style="background: #8b5cf6;" title="Imprimir Comprovante"><i class="fa fa-print"></i></a>

            <a href="?action=excluir_agendamento&id=<?= $ag['id'] ?>&csrf_token=<?= $csrf_token ?>" class="ag-btn-icon ag-btn-dark" onclick="return confirmActionOverlay('Excluir permanentemente?', this)" title="Excluir"><i class="fa fa-trash"></i></a>
        </div>
        <?php
    }
}

if (!function_exists('getVipBadge')) {
    function getVipBadge($ag) {
        $tipo = $ag['tipo_desconto'] ?? '';
        if (in_array($tipo, ['adesao_plano', 'assinatura_vip', 'assinatura', 'plano'])) return '<i class="fa fa-crown text-warning" title="Barbearia por assinatura" style="margin-left: 5px; color: #fbbf24;"></i>';
        return '';
    }
}

if (!function_exists('getPresencaBadge')) {
    // Selo "presença confirmada" quando o cliente confirmou pelo link do lembrete.
    function getPresencaBadge($ag) {
        if (empty($ag['presenca_confirmada'])) return '';
        $qdo = htmlspecialchars(date('d/m H:i', strtotime($ag['presenca_confirmada'])));
        return '<span title="Presença confirmada pelo cliente em ' . $qdo . '" style="margin-left:6px; display:inline-flex; align-items:center; gap:3px; background:#dcfce7; color:#15803d; border-radius:20px; padding:1px 8px; font-size:.68rem; font-weight:800; text-transform:uppercase; vertical-align:middle;"><i class="fa fa-user-check"></i> Confirmou</span>';
    }
}

if (!function_exists('getClickableClientName')) {
    function getClickableClientName($ag) {
        $nome = htmlspecialchars($ag['nome']);
        $id_cliente = $ag['cliente_id_encontrado'] ?? null;
        if (!empty($id_cliente)) return '<a href="#" class="ag-client-link" data-modal-target="#modal-cliente-detalhes" data-id="'.htmlspecialchars($id_cliente).'" title="Ver Detalhes do Cliente">' . $nome . '</a>';
        return '<span class="ag-client-name">' . $nome . '</span>';
    }
}
?>

<style>
    /* ==========================================================
       ESTILOS GERAIS
       ========================================================== */
    #overlay-loading-lembretes, #overlay-loading-acao { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(255,255,255,0.95); z-index: 9999; justify-content: center; align-items: center; flex-direction: column; backdrop-filter: blur(5px); }
    .acao-spinner { font-size: 3.5rem; color: var(--secondary-color, #007bff); margin-bottom: 15px; }

    .agenda-hub { font-family: 'Inter', sans-serif; animation: fadeIn 0.4s ease-out; display: flex; flex-direction: column; gap: 20px; }
    /* .msg-alert / .msg-success / .msg-error vivem em css/admin_components.css */

    .agenda-toolbar { background: #fff; border-radius: 16px; padding: 15px 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; }
    .agenda-title { font-size: 1.4rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px; margin: 0; }
    .agenda-title i { color: var(--secondary-color, #007bff); background: #eff6ff; padding: 10px; border-radius: 12px; font-size: 1.2rem; }

    .agenda-controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .ag-input-group { display: flex; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; padding: 2px; }
    .ag-input-group input, .ag-input-group select { border: none; background: transparent; padding: 8px 12px; font-size: 0.9rem; color: #475569; outline: none; font-weight: 500; cursor: pointer; }
    .ag-input-group input:focus, .ag-input-group select:focus { background: #fff; }

    .ag-btn-filter { background: #0f172a; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px; text-decoration: none;}
    .ag-btn-filter:hover { background: #334155; }
    .ag-btn-clear { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 8px 12px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: 0.2s;}

    .agenda-actions { display: flex; gap: 10px; }
    .ag-btn-primary { background: var(--secondary-color, #007bff); color: white; padding: 10px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; border: none; cursor: pointer; box-shadow: 0 4px 10px rgba(0,123,255,0.2); transition: 0.2s; }
    .ag-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,123,255,0.3); color: white; }
    .ag-btn-outline { background: #fff; color: #475569; border: 1px solid #cbd5e1; padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
    .ag-btn-outline:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    .ag-view-toggle { display: flex; background: #e2e8f0; padding: 4px; border-radius: 10px; }
    .ag-view-toggle a { padding: 6px 16px; border-radius: 8px; color: #64748b; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
    .ag-view-toggle a.active { background: #fff; color: #0f172a; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

    .ag-id-badge { font-size: 0.75rem; color: #64748b; font-family: monospace; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 2px 6px; border-radius: 4px; display: inline-block; letter-spacing: 0.5px; }

    /* ==========================================================
       MODO QUADRO & LISTA (GERAL)
       ========================================================== */
    .ag-board { display: flex; gap: 20px; overflow-x: auto; padding-bottom: 20px; min-height: 600px; align-items: flex-start; scroll-behavior: smooth; }
    .ag-column { flex: 0 0 340px; background: #f8fafc; border-radius: 16px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; overflow: hidden; }
    .ag-col-header { padding: 15px 20px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .ag-col-title { font-weight: 800; color: #1e293b; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; margin: 0; }
    .ag-col-count { background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }
    .ag-col-body { padding: 15px; display: flex; flex-direction: column; gap: 15px; overflow-y: auto; max-height: 75vh; }
    .ag-card { background: #fff; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.2s; border-left: 4px solid #cbd5e1; position: relative; }
    .ag-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.05); border-color: #cbd5e1; }
    .ag-card.status-aprovado { border-left-color: #0ea5e9; }
    .ag-card.status-concluido { border-left-color: #10b981; }
    .ag-card.status-cancelado { border-left-color: #ef4444; opacity: 0.7; }

    .ag-card-time { position: absolute; top: 15px; right: 15px; font-size: 1.1rem; font-weight: 800; color: #0f172a; background: #f1f5f9; padding: 4px 8px; border-radius: 8px; }
    .ag-card-client { margin-bottom: 12px; padding-right: 60px; }
    .ag-client-link { font-weight: 800; color: #1e293b; font-size: 1.05rem; text-decoration: none; transition: 0.2s; }
    .ag-client-link:hover { color: var(--secondary-color, #007bff); }
    .ag-client-name { font-weight: 800; color: #1e293b; font-size: 1.05rem; }

    .whatsapp-link { color: #64748b; text-decoration: none; transition: color 0.2s ease; display: inline-flex; align-items: center; gap: 4px; }
    .whatsapp-link:hover { color: #25D366; }

    .ag-servicos-lista { font-size: 0.9rem; color: #475569; font-weight: 500; margin-bottom: 10px; }
    .ag-price-row { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0; }
    .ag-price { font-weight: 800; color: #059669; font-size: 1.1rem; }

    .ag-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; margin-top: 5px; }
    .pill-purple { background: #f3e8ff; color: #7e22ce; }
    .pill-green { background: #dcfce7; color: #15803d; }
    .pill-emerald { background: #ecfdf5; color: #047857; }

    .ag-observacoes { font-size: 0.8rem; color: #b45309; background: #fffbeb; padding: 6px 10px; border-radius: 6px; margin-top: 8px; font-weight: 500; }
    .ag-produtos-lista { font-size: 0.8rem; background: #f8fafc; padding: 8px; border-radius: 6px; margin-top: 8px; }
    .ag-produtos-lista ul { margin: 4px 0 0 0; padding-left: 15px; color: #475569; }

    .ag-actions { display: flex; gap: 5px; margin-top: 12px; }
    .ag-btn-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; color: white; text-decoration: none; transition: 0.2s; font-size: 0.9rem; }
    .ag-btn-icon:hover { transform: translateY(-2px); filter: brightness(1.1); }
    .ag-btn-success { background: #10b981; }
    .ag-btn-danger { background: #ef4444; }
    .ag-btn-info { background: #0ea5e9; }
    .ag-btn-secondary { background: #64748b; }
    .ag-btn-dark { background: #334155; }

    .ag-list-wrapper { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; overflow-x: auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .ag-table { width: 100%; border-collapse: collapse; text-align: left; }
    .ag-table th { background: #f8fafc; padding: 15px 20px; font-size: 0.85rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    .ag-table td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .ag-table tr:hover td { background: #fdfdfd; }
    .ag-status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block;}
    .badge-aprovado { background: #e0f2fe; color: #0284c7; }
    .badge-concluido { background: #dcfce7; color: #15803d; }
    .badge-cancelado { background: #fee2e2; color: #b91c1c; }

    .ag-date-nav { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
    .ag-date-nav::-webkit-scrollbar { display: none; }
    .ag-date-pill { background: #fff; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 12px; text-align: center; cursor: pointer; transition: 0.2s; min-width: 80px; text-decoration: none; color: #475569; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .ag-date-pill:hover { border-color: #cbd5e1; background: #f8fafc; }
    .ag-date-pill.active { background: var(--secondary-color, #007bff); color: white; border-color: var(--secondary-color, #007bff); box-shadow: 0 4px 10px rgba(0,123,255,0.2); }
    .ag-date-pill .day-name { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 3px; opacity: 0.8; }
    .ag-date-pill .day-num { font-size: 1.2rem; font-weight: 800; display: block; }
    .ag-date-pill .month-year { font-size: 0.65rem; color: #94a3b8; font-weight: 700; display: block; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; transition: 0.2s; }
    .ag-date-pill.active .month-year { color: #e0f2fe; }
</style>

<div id="overlay-loading-lembretes">
    <i class="fa fa-paper-plane email-spinner"></i>
    <h2 style="color: #1e293b; margin-bottom: 5px;">Disparando Lembretes...</h2>
    <p style="color: #64748b; font-size: 1.1rem;">Aguarde na tela. Este processo pode demorar alguns segundos.</p>
</div>
<div id="overlay-loading-acao">
    <i class="fa fa-circle-notch fa-spin acao-spinner"></i>
    <h2 style="color: #1e293b; margin-bottom: 5px; font-size: 1.5rem;">Processando...</h2>
    <p style="color: #64748b; font-size: 1rem;">Aguarde um momento.</p>
</div>

<div class="agenda-hub agenda-premium" data-csrf="<?= htmlspecialchars(generate_csrf_token()) ?>" data-date="<?= htmlspecialchars($filtro_data_agendamento) ?>">

    <?php if(isset($_GET['success_msg'])): ?>
        <div class="msg-alert msg-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($_GET['success_msg']) ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['error_msg'])): ?>
        <div class="msg-alert msg-error"><i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($_GET['error_msg']) ?></div>
    <?php endif; ?>

    <div class="agenda-toolbar">
        <h2 class="agenda-title"><i class="fa fa-calendar-check"></i> Gestão de Agenda</h2>

        <div class="agenda-actions">
            <button class="ag-btn-primary" data-modal-target="#modal-agendamento-manual"><i class="fa fa-plus"></i> Novo</button>
            <a href="<?= $link_imprimir_agenda ?>" target="_blank" class="ag-btn-outline"><i class="fa fa-print"></i> Imprimir</a>
            <a href="admin.php?action=enviar_lembretes_amanha&tab=agendamentos&csrf_token=<?= generate_csrf_token() ?>" class="ag-btn-outline" onclick="if(confirm('Enviar lembretes para os clientes de amanhã? (quem já foi lembrado não recebe de novo)')) { document.getElementById('overlay-loading-lembretes').style.display = 'flex'; return true; } else { return false; }"><i class="fa fa-paper-plane"></i> Lembretes</a>
        </div>
    </div>

    <?php
        $__cronToken = function_exists('getCronToken') ? getCronToken() : '';
        $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $__cronDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        $__cronUrl = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'seu-site') . $__cronDir . '/cron_lembretes.php?token=' . $__cronToken;
        $__lb = function_exists('carregarConfigLembretes') ? carregarConfigLembretes() : ['dia_antes_ativo'=>1,'dia_hora_envio'=>9,'hora_antes_ativo'=>1,'horas_antes'=>2];
    ?>
    <details class="cron-lembretes-box" style="margin:6px 0 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px;">
        <summary style="cursor:pointer; font-weight:700; color:#334155;"><i class="fa fa-robot" style="color:#6366f1;"></i> Lembretes automáticos (véspera + horas antes)</summary>

        <form method="POST" action="admin.php?tab=agendamentos" style="margin:12px 0 4px;">
            <input type="hidden" name="action" value="salvar_config_lembretes">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:flex-end;">
                <div>
                    <label style="display:block; font-weight:600; color:#334155; font-size:.82rem; margin-bottom:4px;">Lembrete na véspera</label>
                    <select name="lb_dia_ativo" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                        <option value="1" <?= !empty($__lb['dia_antes_ativo']) ? 'selected' : '' ?>>Ativado</option>
                        <option value="0" <?= empty($__lb['dia_antes_ativo']) ? 'selected' : '' ?>>Desativado</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:600; color:#334155; font-size:.82rem; margin-bottom:4px;">Enviar a véspera às</label>
                    <select name="lb_dia_hora" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                        <?php for ($h = 0; $h <= 23; $h++): ?>
                            <option value="<?= $h ?>" <?= (int)$__lb['dia_hora_envio'] === $h ? 'selected' : '' ?>><?= sprintf('%02dh', $h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:600; color:#334155; font-size:.82rem; margin-bottom:4px;">Lembrete horas antes</label>
                    <select name="lb_hora_ativo" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                        <option value="1" <?= !empty($__lb['hora_antes_ativo']) ? 'selected' : '' ?>>Ativado</option>
                        <option value="0" <?= empty($__lb['hora_antes_ativo']) ? 'selected' : '' ?>>Desativado</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:600; color:#334155; font-size:.82rem; margin-bottom:4px;">Quantas horas antes</label>
                    <input type="number" name="lb_horas_antes" min="1" max="24" value="<?= (int)$__lb['horas_antes'] ?>" style="width:80px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                </div>
                <button type="submit" class="ag-btn-primary"><i class="fa fa-save"></i> Salvar</button>
            </div>
        </form>

        <p style="margin:12px 0 6px; color:#475569; font-size:.86rem;"><strong>Automação:</strong> agende esta URL no cron da sua hospedagem para rodar <strong>a cada 15–30 min</strong> (o lembrete "horas antes" precisa disso; a véspera sai 1x/dia, na hora escolhida):</p>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="text" readonly value="<?= htmlspecialchars($__cronUrl) ?>" onclick="this.select()" style="flex:1; min-width:260px; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-family:monospace; font-size:.82rem; background:#fff;">
            <button type="button" class="ag-btn-outline" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($__cronUrl, ENT_QUOTES) ?>').then(function(){this.innerHTML='<i class=\'fa fa-check\'></i> Copiado';}.bind(this))"><i class="fa fa-copy"></i> Copiar</button>
        </div>
        <p style="margin:8px 0 0; color:#64748b; font-size:.8rem;">No próprio servidor, via linha de comando: <code>php cron_lembretes.php</code> (sem token). Mantenha este link em segredo. O botão <strong>Lembretes</strong> acima envia a véspera manualmente na hora.</p>
    </details>

    <form method="GET" class="agenda-toolbar" style="padding: 10px 20px; border-radius: 12px; margin-top: -5px;">
        <input type="hidden" name="tab" value="agendamentos">
        <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode) ?>">

        <div class="ag-view-toggle">
            <a href="<?= $link_list_view ?>" class="<?= $view_mode === 'list' ? 'active' : '' ?>" title="Lista Simples"><i class="fa fa-list"></i> Lista</a>
            <a href="<?= $link_board_view ?>" class="<?= $view_mode === 'board' ? 'active' : '' ?>" title="Quadro Visual"><i class="fa fa-columns"></i> Quadro</a>
            <a href="<?= $link_registro_view ?>" class="<?= $view_mode === 'registro' ? 'active' : '' ?>" title="Registro do Dia"><i class="fa fa-clipboard-list"></i> Registro do Dia</a>
        </div>

        <div class="agenda-controls">
            <div class="ag-input-group">
                <i class="fa fa-calendar-day" style="color: #94a3b8; padding-left: 10px;"></i>
                <input type="date" name="filtro_data" value="<?= htmlspecialchars($filtro_data_agendamento) ?>" onchange="this.form.submit()">
            </div>
            <div class="ag-input-group">
                <i class="fa fa-user-tie" style="color: #94a3b8; padding-left: 10px;"></i>
                <select name="filtro_barbeiro" onchange="this.form.submit()">
                    <option value="">Todos os Barbeiros</option>
                    <?php foreach($barbeirosArr as $id => $b): ?>
                        <option value="<?= $id ?>" <?= $filtro_barbeiro == $id ? 'selected' : '' ?>><?= htmlspecialchars($b['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ag-input-group">
                <i class="fa fa-filter" style="color: #94a3b8; padding-left: 10px;"></i>
                <select name="filtro_status" onchange="this.form.submit()">
                    <option value="">Qualquer Status</option>
                    <option value="aprovado" <?= $filtro_status=='aprovado'?'selected':'' ?>>Aprovado</option>
                    <option value="concluido" <?= $filtro_status=='concluido'?'selected':'' ?>>Concluído</option>
                    <option value="cancelado" <?= $filtro_status=='cancelado'?'selected':'' ?>>Cancelado</option>
                </select>
            </div>

            <button type="submit" class="ag-btn-filter" title="Aplicar"><i class="fa fa-search"></i> Buscar</button>
            <a href="admin.php?tab=agendamentos" class="ag-btn-clear" title="Limpar Filtros"><i class="fa fa-times"></i></a>
        </div>
    </form>

    <div class="ag-date-nav">
        <?php
        $data_base = !empty($filtro_data_agendamento) ? strtotime($filtro_data_agendamento) : time();
        $meses_nomes = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        for ($i = -3; $i <= 4; $i++) {
            $dia_calc = strtotime("$i days", $data_base);
            $dia_iso = date('Y-m-d', $dia_calc);
            $dia_semana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][date('w', $dia_calc)];
            $dia_num = date('d', $dia_calc);
            $mes_str = $meses_nomes[(int)date('m', $dia_calc)];
            $ano_str = date('Y', $dia_calc);
            $mes_ano = "{$mes_str} {$ano_str}";
            $active_class = ($dia_iso === $filtro_data_agendamento) ? 'active' : '';

            $url_dia = "admin.php?tab=agendamentos&view_mode={$view_mode}&filtro_data={$dia_iso}&filtro_barbeiro={$filtro_barbeiro}&filtro_status={$filtro_status}";
            echo "<a href='{$url_dia}' class='ag-date-pill {$active_class}'>
                    <span class='day-name'>{$dia_semana}</span>
                    <span class='day-num'>{$dia_num}</span>
                    <span class='month-year'>{$mes_ano}</span>
                  </a>";
        }
        ?>
    </div>

    <?php if($view_mode === 'registro'): ?>
        <?php
            $diaSel = $filtro_data_agendamento ?: date('Y-m-d');
            $pdoReg = getDB();
            $stmtReg = $pdoReg->prepare("SELECT * FROM agendamentos WHERE data = ? ORDER BY hora ASC");
            $stmtReg->execute([$diaSel]);
            $agsDia = $stmtReg->fetchAll(PDO::FETCH_ASSOC);
            if ($filtro_barbeiro !== '') $agsDia = array_values(array_filter($agsDia, fn($a) => ($a['barbeiro_id'] ?? '') === $filtro_barbeiro));
            if ($filtro_status !== '')  $agsDia = array_values(array_filter($agsDia, fn($a) => ($a['status'] ?? '') === $filtro_status));

            $regConcluidos = 0; $regCancelados = 0; $regPendentes = 0;
            $regFaturamento = 0.0; $regGorjetas = 0.0; $regPorPagamento = [];
            foreach ($agsDia as $a) {
                $st = $a['status'] ?? '';
                if ($st === 'concluido') {
                    $regConcluidos++;
                    $valorAg = calcularValorAgendamentoAgenda($a, $servicosArr, $combosArr, $planosArr ?? []);
                    $g = max(0, (float)($a['gorjeta'] ?? 0));
                    $regFaturamento += $valorAg; $regGorjetas += $g;
                    $fp = $a['forma_pagamento'] ?? '';
                    if ($fp !== '') $regPorPagamento[$fp] = ($regPorPagamento[$fp] ?? 0) + $valorAg + $g;
                } elseif ($st === 'cancelado' || $st === 'cancelado_pelo_cliente') {
                    $regCancelados++;
                } else {
                    $regPendentes++;
                }
            }
            $regTicket = $regConcluidos > 0 ? $regFaturamento / $regConcluidos : 0;

            $stmtNovos = $pdoReg->prepare("SELECT COUNT(*) FROM agendamentos WHERE substr(data_criacao,1,10) = ?");
            $stmtNovos->execute([$diaSel]);
            $regNovos = (int)$stmtNovos->fetchColumn();

            $regHistorico = function_exists('obterHistoricoDoDia') ? obterHistoricoDoDia($diaSel) : [];
            $idsDia = array_map(fn($a) => $a['id'], $agsDia);
            $histPorAg = $idsDia ? obterHistoricoAgenda($idsDia) : [];

            if (!function_exists('_regAutor')) {
                function _regAutor($hist, $id, $termos) {
                    foreach (($hist[$id] ?? []) as $h) {
                        foreach ($termos as $t) {
                            if (mb_stripos($h['acao'] ?? '', $t) !== false) return $h['usuario'] ?? '—';
                        }
                    }
                    return '—';
                }
            }
            if (!function_exists('_regEstiloAcao')) {
                function _regEstiloAcao($acao) {
                    $a = mb_strtolower($acao);
                    if (strpos($a, 'conclu') !== false || strpos($a, 'comanda') !== false) return ['fa-check', '#059669', '#ecfdf5'];
                    if (strpos($a, 'cancel') !== false) return ['fa-xmark', '#dc2626', '#fef2f2'];
                    if (strpos($a, 'reagend') !== false) return ['fa-calendar-day', '#b45309', '#fffbeb'];
                    if (strpos($a, 'criado') !== false) return ['fa-plus', '#0369a1', '#eff6ff'];
                    return ['fa-arrows-rotate', '#64748b', '#f1f5f9'];
                }
            }
            $dataFmtReg = date('d/m/Y', strtotime($diaSel));
            $ehHoje = ($diaSel === date('Y-m-d'));
        ?>

        <div class="diario-wrap">
            <div class="diario-head">
                <h3><i class="fa fa-clipboard-list"></i> Registro de <?= $ehHoje ? 'Hoje' : $dataFmtReg ?></h3>
                <span class="diario-sub"><?= $dataFmtReg ?> · use os filtros acima para mudar o dia, profissional ou status</span>
            </div>

            <div class="diario-kpis">
                <div class="diario-kpi"><span class="k-label">Concluídos</span><span class="k-value" style="color:#059669;"><?= $regConcluidos ?></span></div>
                <div class="diario-kpi"><span class="k-label">Cancelados</span><span class="k-value" style="color:#dc2626;"><?= $regCancelados ?></span></div>
                <div class="diario-kpi"><span class="k-label">Em aberto</span><span class="k-value" style="color:#b45309;"><?= $regPendentes ?></span></div>
                <div class="diario-kpi"><span class="k-label">Novos criados</span><span class="k-value"><?= $regNovos ?></span></div>
                <div class="diario-kpi"><span class="k-label">Faturamento</span><span class="k-value">R$ <?= number_format($regFaturamento, 2, ',', '.') ?></span></div>
                <div class="diario-kpi"><span class="k-label">Gorjetas</span><span class="k-value">R$ <?= number_format($regGorjetas, 2, ',', '.') ?></span></div>
                <div class="diario-kpi"><span class="k-label">Ticket médio</span><span class="k-value">R$ <?= number_format($regTicket, 2, ',', '.') ?></span></div>
            </div>

            <?php if (!empty($regPorPagamento)): ?>
            <div class="diario-pagamentos">
                <strong>Recebido por forma de pagamento:</strong>
                <?php foreach ($regPorPagamento as $fp => $val): ?>
                    <span class="diario-pag-chip"><?= htmlspecialchars(rotuloFormaPagamento($fp) ?: $fp) ?>: <b>R$ <?= number_format($val, 2, ',', '.') ?></b></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            // Monta o resumo do dia para o briefing da IA (inclui aniversariantes e estoque a repor).
            $briefAniversariantes = 0;
            $mdBrief = date('m-d', strtotime($diaSel));
            foreach (($clientesArr ?? []) as $cB) {
                $nB = (string)($cB['data_nascimento'] ?? '');
                if ($nB !== '' && ($tB = strtotime($nB)) !== false && date('m-d', $tB) === $mdBrief) $briefAniversariantes++;
            }
            $briefRepor = 0;
            foreach (($produtosArr ?? []) as $pB) {
                $qB = (int)($pB['quantidade'] ?? 0); $mB = (int)($pB['estoque_minimo'] ?? 5);
                if ($qB <= $mB) $briefRepor++;
            }
            $topBarbeiroDia = '';
            if (!empty($agsDia)) {
                $porBarb = [];
                foreach ($agsDia as $aB) { $porBarb[$aB['barbeiro_id'] ?? ''] = ($porBarb[$aB['barbeiro_id'] ?? ''] ?? 0) + 1; }
                arsort($porBarb);
                $topId = array_key_first($porBarb);
                $topBarbeiroDia = ($barbeirosArr[$topId]['nome'] ?? '') . ' (' . reset($porBarb) . ')';
            }
            $briefingDados = "Data: {$dataFmtReg}\n"
                . "Total de atendimentos: " . count($agsDia) . "\n"
                . "Concluídos: {$regConcluidos} | Em aberto: {$regPendentes} | Cancelados: {$regCancelados}\n"
                . "Faturamento estimado: R$ " . number_format($regFaturamento, 2, ',', '.') . " | Ticket médio: R$ " . number_format($regTicket, 2, ',', '.') . "\n"
                . "Gorjetas: R$ " . number_format($regGorjetas, 2, ',', '.') . "\n"
                . "Profissional com mais atendimentos: " . ($topBarbeiroDia ?: '—') . "\n"
                . "Aniversariantes de hoje: {$briefAniversariantes}\n"
                . "Produtos a repor no estoque: {$briefRepor}";
            ?>
            <div class="diario-briefing">
                <div class="db-head">
                    <h4><i class="fa fa-robot"></i> Briefing do dia (IA)</h4>
                    <button type="button" id="btn-briefing-ia" class="db-btn" data-dados="<?= htmlspecialchars($briefingDados) ?>"><i class="fa fa-wand-magic-sparkles"></i> Gerar briefing</button>
                </div>
                <div id="briefing-texto" class="db-texto"><i class="fa fa-info-circle"></i> Clique em "Gerar briefing" para a IA resumir o dia e apontar prioridades.</div>
            </div>

            <div class="diario-grid">
                <div class="diario-col">
                    <h4><i class="fa fa-stream"></i> Linha do tempo do dia</h4>
                    <?php if (empty($regHistorico)): ?>
                        <p class="diario-empty">Nenhuma atividade registrada neste dia ainda.</p>
                    <?php else: ?>
                        <div class="diario-timeline">
                            <?php foreach ($regHistorico as $h):
                                [$ic, $cor, $bg] = _regEstiloAcao($h['acao'] ?? '');
                                $barbNome = $barbeirosArr[$h['barbeiro_id']]['nome'] ?? '';
                            ?>
                                <div class="diario-tl-item">
                                    <div class="diario-tl-icon" style="color:<?= $cor ?>; background:<?= $bg ?>;"><i class="fa <?= $ic ?>"></i></div>
                                    <div class="diario-tl-body">
                                        <div class="diario-tl-top">
                                            <strong><?= htmlspecialchars($h['acao'] ?? '') ?></strong>
                                            <time><?= date('H:i', strtotime($h['created_at'])) ?></time>
                                        </div>
                                        <div class="diario-tl-meta">
                                            <?php if (!empty($h['cliente_nome'])): ?><span><i class="fa fa-user"></i> <?= htmlspecialchars($h['cliente_nome']) ?></span><?php endif; ?>
                                            <?php if ($barbNome): ?><span><i class="fa fa-user-tie"></i> <?= htmlspecialchars($barbNome) ?></span><?php endif; ?>
                                            <span><i class="fa fa-user-pen"></i> por <?= htmlspecialchars($h['usuario'] ?: 'Sistema') ?></span>
                                        </div>
                                        <?php if (!empty($h['detalhes'])): ?><div class="diario-tl-det"><?= htmlspecialchars($h['detalhes']) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="diario-col">
                    <h4><i class="fa fa-calendar-check"></i> Atendimentos do dia (<?= count($agsDia) ?>)</h4>
                    <?php if (empty($agsDia)): ?>
                        <p class="diario-empty">Nenhum agendamento para este dia.</p>
                    <?php else: ?>
                        <div class="diario-table-wrap">
                            <table class="diario-table">
                                <thead><tr><th>Hora</th><th>Cliente</th><th>Profissional</th><th>Status</th><th>Valor</th><th>Pagamento</th><th>Criou</th><th>Concluiu</th></tr></thead>
                                <tbody>
                                    <?php foreach ($agsDia as $a):
                                        $valorAg = calcularValorAgendamentoAgenda($a, $servicosArr, $combosArr, $planosArr ?? []);
                                        $g = max(0, (float)($a['gorjeta'] ?? 0));
                                        $fpLabel = rotuloFormaPagamento($a['forma_pagamento'] ?? '');
                                        $quemCriou = _regAutor($histPorAg, $a['id'], ['criado']);
                                        $quemConcluiu = ($a['status'] ?? '') === 'concluido' ? _regAutor($histPorAg, $a['id'], ['conclu', 'comanda']) : '—';
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($a['hora'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($a['nome'] ?? 'Cliente') ?></td>
                                            <td><?= htmlspecialchars($barbeirosArr[$a['barbeiro_id']]['nome'] ?? '—') ?></td>
                                            <td><span class="ag-status-badge badge-<?= htmlspecialchars($a['status'] ?? '') ?>"><?= htmlspecialchars($a['status'] ?? '') ?></span></td>
                                            <td>R$ <?= number_format($valorAg + $g, 2, ',', '.') ?><?php if ($g > 0): ?><small style="color:#94a3b8;"> (+<?= number_format($g, 2, ',', '.') ?> gorj.)</small><?php endif; ?></td>
                                            <td><?= $fpLabel !== '' ? htmlspecialchars($fpLabel) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                                            <td><?= htmlspecialchars($quemCriou) ?></td>
                                            <td><?= htmlspecialchars($quemConcluiu) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif($view_mode === 'board'): ?>

        <div class="ag-board">
            <?php if (empty($agendamentosAgrupadosPorBarbeiro)): ?>
                <div style="width: 100%; text-align: center; padding: 60px; background: #fff; border-radius: 16px; border: 2px dashed #cbd5e1; color: #64748b;">
                    <i class="fa fa-calendar-times" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 15px; display: block;"></i>
                    Nenhum agendamento para esta data/filtros.
                </div>
            <?php else: ?>
                <?php foreach ($barbeirosArr as $bid => $barbeiro):
                    if(!empty($filtro_barbeiro) && $filtro_barbeiro != $bid) continue;
                    $agendamentos_deste_barbeiro = $agendamentosAgrupadosPorBarbeiro[$bid] ?? [];
                ?>
                    <div class="ag-column">
                        <div class="ag-col-header">
                            <h3 class="ag-col-title">
                                <?php if($barbeiro['foto']): ?>
                                    <img src="<?= htmlspecialchars($barbeiro['foto']) ?>" style="width:30px; height:30px; border-radius:50%; object-fit:cover;">
                                <?php else: ?>
                                    <i class="fa fa-user-circle"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($barbeiro['nome']) ?>
                            </h3>
                            <span class="ag-col-count"><?= count($agendamentos_deste_barbeiro) ?></span>
                        </div>
                        <div class="ag-col-body">
                            <?php if(empty($agendamentos_deste_barbeiro)): ?>
                                <p style="text-align: center; color: #94a3b8; font-size: 0.9rem; margin-top: 20px;">Agenda livre</p>
                            <?php else: ?>
                                <?php foreach($agendamentos_deste_barbeiro as $ag):
                                    $ag['cliente_id_encontrado'] = findClienteId($ag, $clientesArr);

                                    $cid = $ag['cliente_id_encontrado'];
                                    $foto_cliente = 'uploads/default-profile.jpg';
                                    if (!empty($cid) && isset($clientesArr[$cid]) && !empty(trim($clientesArr[$cid]['foto_perfil'] ?? ''))) {
                                        $path = trim($clientesArr[$cid]['foto_perfil']);
                                        if (file_exists($path)) {
                                            $foto_cliente = $path;
                                        }
                                    }

                                    $valor_total = 0;
                                    foreach(explode(',', $ag['servicos_ids']) as $sid) {
                                        $sid = trim($sid);
                                        if(isset($servicosArr[$sid])) { $valor_total += $servicosArr[$sid]['valor']; }
                                        elseif(isset($combosArr[$sid])) { $valor_total += $combosArr[$sid]['valor']; }
                                    }
                                    $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
                                    $valor_total_produtos = array_sum(array_column($produtos_vendidos, 'valor'));
                                    $valor_final = $valor_total + $valor_total_produtos - (float)($ag['desconto_aplicado'] ?? 0);

                                    if (isset($ag['tipo_desconto']) && $ag['tipo_desconto'] === 'adesao_plano' && !empty($ag['plano_provisorio'])) {
                                        $id_plano = $ag['plano_provisorio'];
                                        if (isset($planosArr[$id_plano])) {
                                            $valor_final += (float)$planosArr[$id_plano]['valor'];
                                        }
                                    }

                                    $numero_whatsapp = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? '');
                                    if (strlen($numero_whatsapp) == 10 || strlen($numero_whatsapp) == 11) { $numero_whatsapp = "55" . $numero_whatsapp; }
                                ?>
                                <div class="ag-card status-<?= $ag['status'] ?>">
                                    <div class="ag-card-time"><?= $ag['hora'] ?></div>
                                    <div class="ag-card-client">
                                        <div class="ag-id-badge" style="margin-bottom: 8px;">#<?= $ag['id'] ?></div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <img src="<?= htmlspecialchars($foto_cliente) ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0;">
                                            <div>
                                                <?= getClickableClientName($ag) ?><?= getVipBadge($ag) ?><?= getPresencaBadge($ag) ?>
                                                <div class="ag-client-phone">
                                                    <a href="https://wa.me/<?= $numero_whatsapp ?>" target="_blank" class="whatsapp-link"><i class="fab fa-whatsapp"></i> <?= htmlspecialchars($ag['telefone']) ?></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php renderAgendamentoDetalhes($ag, $servicosArr, $planosArr, $combosArr, $assinaturasClientesArr); ?>

                                    <div class="ag-price-row">
                                        <span class="ag-price">R$ <?= number_format($valor_final, 2, ',', '.') ?></span>
                                        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b;"><?= $ag['status'] ?></span>
                                    </div>

                                    <?php renderAgendamentoActions($ag); ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php else: /* MODO LISTA (PADRÃO) */ ?>

        <div class="ag-list-wrapper">
            <table class="ag-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Profissional</th>
                        <th>Hora</th>
                        <th>Serviços / Detalhes</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($agendamentosPaginados)): ?>
                    <tr><td colspan="7" style="text-align:center; padding: 40px; color: #64748b;">Nenhum agendamento encontrado.</td></tr>
                <?php else: ?>
                    <?php
                    foreach ($agendamentosPaginados as $ag):
                        $ag['cliente_id_encontrado'] = findClienteId($ag, $clientesArr);

                        $cid = $ag['cliente_id_encontrado'];
                        $foto_cliente = 'uploads/default-profile.jpg';
                        if (!empty($cid) && isset($clientesArr[$cid]) && !empty(trim($clientesArr[$cid]['foto_perfil'] ?? ''))) {
                            $path = trim($clientesArr[$cid]['foto_perfil']);
                            if (file_exists($path)) {
                                $foto_cliente = $path;
                            }
                        }

                        $valor_total = 0;
                        foreach(explode(',', $ag['servicos_ids']) as $sid) {
                            $sid = trim($sid);
                            if(isset($servicosArr[$sid])) { $valor_total += $servicosArr[$sid]['valor']; }
                            elseif(isset($combosArr[$sid])) { $valor_total += $combosArr[$sid]['valor']; }
                        }
                        $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
                        $valor_total_produtos = array_sum(array_column($produtos_vendidos, 'valor'));
                        $valor_final = $valor_total + $valor_total_produtos - (float)($ag['desconto_aplicado'] ?? 0);

                        if (isset($ag['tipo_desconto']) && $ag['tipo_desconto'] === 'adesao_plano' && !empty($ag['plano_provisorio'])) {
                            $id_plano = $ag['plano_provisorio'];
                            if (isset($planosArr[$id_plano])) {
                                $valor_final += (float)$planosArr[$id_plano]['valor'];
                            }
                        }

                        $numero_whatsapp = preg_replace('/[^0-9]/', '', $ag['telefone'] ?? '');
                        if (strlen($numero_whatsapp) == 10 || strlen($numero_whatsapp) == 11) { $numero_whatsapp = "55" . $numero_whatsapp; }
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <img src="<?= htmlspecialchars($foto_cliente) ?>" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0;">
                                <div>
                                    <div class="ag-id-badge" style="margin-bottom: 4px;">#<?= $ag['id'] ?></div>
                                    <div style="margin-bottom: 2px;"><?= getClickableClientName($ag) ?><?= getVipBadge($ag) ?><?= getPresencaBadge($ag) ?></div>
                                    <span style="font-size: 0.8rem; color: #64748b;">
                                        <a href="https://wa.me/<?= $numero_whatsapp ?>" target="_blank" class="whatsapp-link"><i class="fab fa-whatsapp"></i> <?= htmlspecialchars($ag['telefone']) ?></a>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;"><?= htmlspecialchars($barbeirosArr[$ag['barbeiro_id']]['nome'] ?? '?') ?></span>
                        </td>
                        <td><strong style="font-size: 1.1rem; color: #0f172a;"><?= $ag['hora'] ?></strong></td>
                        <td><?php renderAgendamentoDetalhes($ag, $servicosArr, $planosArr, $combosArr, $assinaturasClientesArr); ?></td>
                        <td><strong style="color: #059669; font-size: 1.1rem;">R$ <?= number_format($valor_final, 2, ',', '.') ?></strong></td>
                        <td><span class="ag-status-badge badge-<?= $ag['status'] ?>"><?= $ag['status'] ?></span></td>
                        <td style="display: flex; justify-content: flex-end; gap: 5px;">
                            <?php renderAgendamentoActions($ag); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?tab=agendamentos&view=<?= $view ?>&view_mode=<?= $view_mode ?>&filtro_data=<?= urlencode($filtro_data_agendamento) ?>&filtro_horario=<?= urlencode($filtro_horario) ?>&filtro_barbeiro=<?= urlencode($filtro_barbeiro) ?>&filtro_status=<?= urlencode($filtro_status) ?>&page=<?= $i ?>" style="padding: 8px 14px; background: <?= ($i == $currentPage) ? 'var(--secondary-color, #007bff)' : '#fff' ?>; color: <?= ($i == $currentPage) ? '#fff' : '#475569' ?>; border: 1px solid <?= ($i == $currentPage) ? 'var(--secondary-color, #007bff)' : '#e2e8f0' ?>; border-radius: 8px; text-decoration: none; font-weight: 600;"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
function showActionOverlay(btnElement) {
    if(btnElement) {
        btnElement.style.pointerEvents = 'none';
        btnElement.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    }
    document.getElementById('overlay-loading-acao').style.display = 'flex';
}

function confirmActionOverlay(msg, btnElement) {
    if(confirm(msg)) {
        showActionOverlay(btnElement);
        return true;
    }
    return false;
}

// --- Briefing do dia (IA) ---
(function () {
    var btn = document.getElementById('btn-briefing-ia');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var alvo = document.getElementById('briefing-texto');
        var original = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Gerando...';
        if (alvo) alvo.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A IA está analisando o dia...';
        fetch('ajax_gemini.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ action: 'briefing_dia', dados_dia: btn.dataset.dados || '' })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) throw new Error((data && data.error) || 'Falha na IA');
            if (alvo) alvo.innerHTML = (data.resposta || '').replace(/\n/g, '<br>');
        })
        .catch(function (err) { if (alvo) alvo.innerHTML = '<span style="color:#dc2626;"><i class="fa fa-triangle-exclamation"></i> ' + err.message + '</span>'; })
        .finally(function () { btn.disabled = false; btn.innerHTML = original; });
    });
})();
</script>

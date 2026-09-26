<?php
// Aba administrativa de Assinaturas.
// Reaproveita os dados já carregados por admin_data.php:
//   $assinaturasClientesArr, $planosArr, $clientesArr,
//   $totalAssinantesAtivos, $mrrAtual, $planosPopulares.
$csrf_token = generate_csrf_token();
$hoje = date('Y-m-d');

$filtro_status_assin = $_GET['filtro_status_assin'] ?? '';
$busca_assin = trim($_GET['busca_assin'] ?? '');

// Receita de assinaturas confirmada no mês corrente.
$receitaMesAssinaturas = 0.0;
if (function_exists('getPagamentosAssinaturaPeriodo')) {
    $pagMes = getPagamentosAssinaturaPeriodo(date('Y-m-01'), $hoje, $assinaturasClientesArr, $planosArr);
    foreach ($pagMes as $pg) {
        $receitaMesAssinaturas += (float)($pg['valor'] ?? 0);
    }
}

// Monta as linhas enriquecidas para a tabela.
$linhasAssin = [];
$totalCancelAgendado = 0;
$totalVencendo7 = 0;
foreach ($assinaturasClientesArr as $assin) {
    $clienteId = $assin['cliente_id'] ?? '';
    $planoId   = $assin['plano_id'] ?? '';
    $dataFim   = $assin['data_fim'] ?? '';
    $statusRaw = $assin['status'] ?? '';

    $ativaComBeneficio = in_array($statusRaw, ['ativo', 'cancelamento_agendado'], true) && $dataFim >= $hoje;
    $cancelAgendado = $statusRaw === 'cancelamento_agendado' && $dataFim >= $hoje;

    if ($ativaComBeneficio) {
        $situacao = $cancelAgendado ? 'cancelamento_agendado' : 'ativa';
    } else {
        $situacao = 'encerrada';
    }

    // Filtro por situação
    if ($filtro_status_assin !== '' && $situacao !== $filtro_status_assin) {
        continue;
    }

    $cliente = $clientesArr[$clienteId] ?? null;
    $nomeCliente = $cliente['nome'] ?? 'Cliente removido';
    $emailCliente = $cliente['email'] ?? '';
    $telCliente = $cliente['telefone'] ?? '';

    // Busca textual
    if ($busca_assin !== '') {
        $alvo = mb_strtolower($nomeCliente . ' ' . $emailCliente . ' ' . $telCliente . ' ' . $clienteId);
        if (mb_strpos($alvo, mb_strtolower($busca_assin)) === false) {
            continue;
        }
    }

    $plano = $planosArr[$planoId] ?? null;
    $nomePlano = $plano['nome'] ?? 'Plano removido';
    $valorPlano = (float)($plano['valor'] ?? 0);

    $diasRestantes = $dataFim !== '' ? (int)ceil((strtotime($dataFim) - time()) / 86400) : 0;
    if ($diasRestantes < 0) $diasRestantes = 0;

    if ($situacao === 'cancelamento_agendado') $totalCancelAgendado++;
    if ($situacao === 'ativa' && $diasRestantes <= 7) $totalVencendo7++;

    $linhasAssin[] = [
        'cliente_id' => $clienteId,
        'nome_cliente' => $nomeCliente,
        'email' => $emailCliente,
        'telefone' => $telCliente,
        'nome_plano' => $nomePlano,
        'valor_plano' => $valorPlano,
        'data_inicio' => $assin['data_inicio'] ?? '',
        'data_fim' => $dataFim,
        'dias_restantes' => $diasRestantes,
        'gateway' => $assin['gateway'] ?? 'manual',
        'situacao' => $situacao,
        'gateway_sub_id' => $assin['gateway_subscription_id'] ?? '',
    ];
}

// Ordena: ativas primeiro, depois cancelamento agendado, depois encerradas; por vencimento.
$ordemSituacao = ['ativa' => 0, 'cancelamento_agendado' => 1, 'encerrada' => 2];
usort($linhasAssin, function ($a, $b) use ($ordemSituacao) {
    $sa = $ordemSituacao[$a['situacao']] ?? 9;
    $sb = $ordemSituacao[$b['situacao']] ?? 9;
    if ($sa !== $sb) return $sa <=> $sb;
    return strcmp($a['data_fim'], $b['data_fim']);
});

$totalLinhas = count($linhasAssin);
$ticketMedio = $totalAssinantesAtivos > 0 ? ($mrrAtual / $totalAssinantesAtivos) : 0;

$planoTop = '—';
if (!empty($planosPopulares)) {
    $planoTop = array_key_first($planosPopulares);
}

$filtrosAtivos = $filtro_status_assin !== '' || $busca_assin !== '';
?>

<style>
    .assin-layout { display: flex; flex-direction: column; gap: 18px; animation: fadeIn .4s ease-out; }
    .icon-assin-ativos { background: #f5f3ff; color: #7c3aed; }
    .icon-assin-mrr { background: #ecfdf5; color: #10b981; }
    .icon-assin-mes { background: #eff6ff; color: #3b82f6; }
    .icon-assin-cancel { background: #fffbeb; color: #f59e0b; }
    .icon-assin-ticket { background: #fef2f2; color: #ef4444; }

    .assin-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .assin-search-control { position: relative; flex: 1 1 240px; min-width: 0; }
    .assin-search-control > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
    .assin-toolbar .filter-input { width: 100%; box-sizing: border-box; height: 44px; padding: 0 14px 0 40px; border: 1px solid #d8e0ea; border-radius: 8px; outline: none; background: #fff; color: #27364b; font: 600 .88rem 'Inter', sans-serif; }
    .assin-toolbar .filter-input::placeholder { color: #94a3b8; font-weight: 500; }
    .assin-toolbar .modern-select { flex: 0 1 200px; min-width: 160px; height: 44px; padding: 0 14px; background: #fff; cursor: pointer; }
    .assin-toolbar .btn-modern-filter { width: 44px; height: 44px; min-height: 44px; padding: 0; flex-shrink: 0; }
    .btn-clear-filter { width: 44px; height: 44px; flex-shrink: 0; border: 1px solid #d8e0ea; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #64748b; background: #fff; text-decoration: none; }
    .btn-clear-filter:hover { color: #dc2626; border-color: #fecaca; background: #fff7f7; }

    .assin-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
    table.assin-table { width: 100%; border-collapse: collapse; min-width: 860px; }
    table.assin-table th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; padding: 13px 16px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.assin-table td { padding: 13px 16px; border-bottom: 1px solid #f1f5f9; font-size: .86rem; color: #1e293b; vertical-align: middle; }
    table.assin-table tr:last-child td { border-bottom: 0; }
    table.assin-table tr:hover td { background: #fcfdff; }
    .assin-cli-nome { font-weight: 700; color: #0f172a; }
    .assin-cli-contato { font-size: .74rem; color: #94a3b8; }
    .assin-plano-nome { font-weight: 600; }
    .assin-valor { font-weight: 700; color: #059669; white-space: nowrap; }
    .assin-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
    .assin-badge.ativa { background: #dcfce7; color: #166534; }
    .assin-badge.cancelamento_agendado { background: #fef3c7; color: #92400e; }
    .assin-badge.encerrada { background: #f1f5f9; color: #64748b; }
    .assin-gateway { display: inline-flex; align-items: center; gap: 5px; font-size: .74rem; color: #475569; }
    .assin-venc { white-space: nowrap; }
    .assin-venc small { display: block; color: #94a3b8; font-size: .68rem; }
    .assin-venc strong { color: #0f172a; }
    .assin-venc.is-urgent strong { color: #dc2626; }
    .assin-actions { display: flex; gap: 6px; justify-content: flex-end; }

    /* Aviso de assinaturas vencendo — em classe (e não em style inline) para
       o tema escuro conseguir sobrescrever. */
    .assin-alert-vencendo { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
    .assin-alert-vencendo .modern-alert-icon { color: #f59e0b; }

    @media (max-width: 768px) {
        .assin-toolbar { flex-direction: column; align-items: stretch; }
        .assin-toolbar .modern-select, .assin-toolbar .btn-modern-filter, .btn-clear-filter { width: 100%; }
    }
</style>

<div class="assin-layout">
    <div class="dash-header-bar">
        <div class="dash-title">
            <h3>Assinaturas</h3>
            <p>Todos os clientes com Barbearia por Assinatura — plano, validade, renovação e receita.</p>
        </div>
        <a href="admin.php?tab=servicos&subtab=planos" class="btn-modern btn-modern-add" style="text-decoration:none;">
            <i class="fa fa-sliders"></i> Gerenciar planos
        </a>
    </div>

    <div class="kpi-grid" aria-label="Resumo de assinaturas">
        <div class="kpi-card">
            <div class="kpi-icon icon-assin-ativos"><i class="fa fa-crown"></i></div>
            <div class="kpi-info">
                <h4>Assinantes ativos</h4>
                <div class="value"><?= (int)$totalAssinantesAtivos ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-assin-mrr"><i class="fa fa-arrows-rotate"></i></div>
            <div class="kpi-info">
                <h4>Receita recorrente (MRR)</h4>
                <div class="value">R$ <?= number_format((float)$mrrAtual, 2, ',', '.') ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-assin-mes"><i class="fa fa-sack-dollar"></i></div>
            <div class="kpi-info">
                <h4>Recebido este mês</h4>
                <div class="value">R$ <?= number_format((float)$receitaMesAssinaturas, 2, ',', '.') ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-assin-cancel"><i class="fa fa-hourglass-half"></i></div>
            <div class="kpi-info">
                <h4>Cancelamento agendado</h4>
                <div class="value" style="<?= $totalCancelAgendado > 0 ? 'color:#f59e0b;' : '' ?>"><?= (int)$totalCancelAgendado ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-assin-ticket"><i class="fa fa-receipt"></i></div>
            <div class="kpi-info">
                <h4>Ticket médio / mês</h4>
                <div class="value">R$ <?= number_format((float)$ticketMedio, 2, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <?php if ($totalVencendo7 > 0): ?>
        <div class="modern-alert assin-alert-vencendo">
            <div class="modern-alert-icon"><i class="fa fa-bell"></i></div>
            <div class="modern-alert-content">
                <p class="modern-alert-text"><strong><?= (int)$totalVencendo7 ?></strong> assinatura<?= $totalVencendo7 === 1 ? '' : 's' ?> vence<?= $totalVencendo7 === 1 ? '' : 'm' ?> nos próximos 7 dias. As pagas via Stripe renovam automaticamente; as manuais precisam de renovação.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php
        $__cronTokenAssin = function_exists('getCronToken') ? getCronToken() : '';
        $__schemeAssin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $__cronDirAssin = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        $__cronUrlAssin = $__schemeAssin . '://' . ($_SERVER['HTTP_HOST'] ?? 'seu-site') . $__cronDirAssin . '/cron_lembretes.php?token=' . $__cronTokenAssin;
    ?>
    <details class="cron-assinaturas-box" style="margin:6px 0 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px;">
        <summary style="cursor:pointer; font-weight:700; color:#334155;"><i class="fa fa-robot" style="color:#6366f1;"></i> Como as assinaturas se mantêm em dia (renovação, cancelamento e expiração)</summary>

        <div style="margin-top:12px; color:#475569; font-size:.9rem; line-height:1.65;">
            <p style="margin:0 0 10px;"><i class="fa fa-shield-halved" style="color:#16a34a;"></i> <strong>O cliente nunca usa o benefício depois do vencimento.</strong> O desconto de assinante é liberado por <strong>data de validade</strong> a cada agendamento — passou da validade, o benefício para na hora, mesmo que nada mais rode.</p>

            <p style="margin:0 0 6px; font-weight:700; color:#334155;">Quem mantém o <em>status</em> na tabela sempre correto:</p>
            <ul style="margin:0 0 10px; padding-left:20px;">
                <li><strong>Stripe (automático):</strong> pagamento, renovação mensal, cancelamento e reativação chegam pelo webhook e atualizam na hora. Exige os 4 eventos configurados — veja <a href="admin.php?tab=configuracoes" style="color:#6366f1; font-weight:600;">Configurações &rarr; Stripe &rarr; Ver Tutorial</a>.</li>
                <li><strong>Ao abrir esta página:</strong> qualquer assinatura vencida é marcada como <span class="assin-badge encerrada" style="padding:1px 7px;">Encerrada</span> automaticamente.</li>
                <li><strong>Na conta do próprio cliente:</strong> quando ele acessa, a assinatura vencida dele também é encerrada sozinha.</li>
                <li><strong>Cron (opcional, recomendado):</strong> cobre o cliente que some e nunca mais acessa, e mantém tudo em dia mesmo sem ninguém abrir o painel.</li>
            </ul>

            <p style="margin:0 0 6px; color:#475569;"><strong>Automação por cron</strong> — agende esta URL na sua hospedagem para rodar <strong>1x por dia</strong> (é o mesmo cron dos lembretes; expira as vencidas e mantém os números certos):</p>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" readonly value="<?= htmlspecialchars($__cronUrlAssin) ?>" onclick="this.select()" style="flex:1; min-width:260px; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-family:monospace; font-size:.82rem; background:#fff;">
                <button type="button" class="btn-modern btn-modern-filter" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($__cronUrlAssin, ENT_QUOTES) ?>').then(function(){this.innerHTML='<i class=\'fa fa-check\'></i> Copiado';}.bind(this))"><i class="fa fa-copy"></i> Copiar</button>
            </div>
            <p style="margin:8px 0 0; color:#64748b; font-size:.82rem;">No próprio servidor, via linha de comando: <code>php cron_lembretes.php</code> (sem token). Mantenha este link em segredo. Há uma tolerância de 1 dia antes de expirar, para dar tempo da renovação automática do Stripe cair.</p>
        </div>
    </details>

    <form method="GET" class="assin-toolbar">
        <input type="hidden" name="tab" value="assinaturas">
        <div class="assin-search-control">
            <i class="fa fa-search"></i>
            <input type="search" name="busca_assin" class="filter-input" placeholder="Nome, e-mail, telefone ou ID do cliente" value="<?= htmlspecialchars($busca_assin) ?>">
        </div>
        <select name="filtro_status_assin" class="modern-select" aria-label="Filtrar por situação">
            <option value="">Todas as situações</option>
            <option value="ativa" <?= $filtro_status_assin === 'ativa' ? 'selected' : '' ?>>Ativas</option>
            <option value="cancelamento_agendado" <?= $filtro_status_assin === 'cancelamento_agendado' ? 'selected' : '' ?>>Cancelamento agendado</option>
            <option value="encerrada" <?= $filtro_status_assin === 'encerrada' ? 'selected' : '' ?>>Encerradas</option>
        </select>
        <button type="submit" class="btn-modern btn-modern-filter" title="Aplicar filtros" aria-label="Aplicar filtros"><i class="fa fa-filter"></i></button>
        <?php if ($filtrosAtivos): ?>
            <a href="?tab=assinaturas" class="btn-clear-filter" title="Limpar filtros" aria-label="Limpar filtros"><i class="fa fa-times"></i></a>
        <?php endif; ?>
    </form>

    <div class="client-results-bar" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="client-results-count" style="color:#64748b; font-size:.84rem;">
            <strong><?= (int)$totalLinhas ?></strong> assinatura<?= $totalLinhas === 1 ? '' : 's' ?>
            <?php if ($planoTop !== '—'): ?> · plano mais popular: <strong><?= htmlspecialchars($planoTop) ?></strong><?php endif; ?>
        </span>
    </div>

    <?php if (empty($linhasAssin)): ?>
        <div class="empty-state">
            <i class="fa fa-crown"></i>
            <h3 style="margin:0 0 5px; color:#1e293b;">Nenhuma assinatura encontrada</h3>
            <p style="margin:0;"><?= $filtrosAtivos ? 'Ajuste os filtros para ver outras assinaturas.' : 'Quando um cliente aderir a um plano, ele aparecerá aqui.' ?></p>
        </div>
    <?php else: ?>
        <div class="assin-table-wrap">
            <table class="assin-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Plano</th>
                        <th>Valor/mês</th>
                        <th>Situação</th>
                        <th>Início</th>
                        <th>Validade</th>
                        <th>Pagamento</th>
                        <th style="text-align:right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhasAssin as $l):
                        $situacaoLabel = ['ativa' => 'Ativa', 'cancelamento_agendado' => 'Cancelamento agendado', 'encerrada' => 'Encerrada'][$l['situacao']];
                        $situacaoIcone = ['ativa' => 'fa-circle-check', 'cancelamento_agendado' => 'fa-hourglass-half', 'encerrada' => 'fa-circle-xmark'][$l['situacao']];
                        $vencUrgente = $l['situacao'] === 'ativa' && $l['dias_restantes'] <= 7;
                        $isStripe = ($l['gateway'] === 'stripe');
                        $whatsapp = preg_replace('/\D+/', '', (string)$l['telefone']);
                        if ($whatsapp !== '' && strlen($whatsapp) <= 11) $whatsapp = '55' . $whatsapp;
                    ?>
                        <tr>
                            <td>
                                <div class="assin-cli-nome"><?= htmlspecialchars($l['nome_cliente']) ?></div>
                                <div class="assin-cli-contato"><?= htmlspecialchars($l['email'] ?: ($l['telefone'] ?: $l['cliente_id'])) ?></div>
                            </td>
                            <td><span class="assin-plano-nome"><?= htmlspecialchars($l['nome_plano']) ?></span></td>
                            <td><span class="assin-valor">R$ <?= number_format($l['valor_plano'], 2, ',', '.') ?></span></td>
                            <td><span class="assin-badge <?= $l['situacao'] ?>"><i class="fa <?= $situacaoIcone ?>"></i> <?= $situacaoLabel ?></span></td>
                            <td><?= $l['data_inicio'] !== '' ? date('d/m/Y', strtotime($l['data_inicio'])) : '—' ?></td>
                            <td>
                                <div class="assin-venc <?= $vencUrgente ? 'is-urgent' : '' ?>">
                                    <strong><?= $l['data_fim'] !== '' ? date('d/m/Y', strtotime($l['data_fim'])) : '—' ?></strong>
                                    <?php if ($l['situacao'] !== 'encerrada'): ?>
                                        <small><?= (int)$l['dias_restantes'] ?> dia<?= $l['dias_restantes'] === 1 ? '' : 's' ?> restante<?= $l['dias_restantes'] === 1 ? '' : 's' ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="assin-gateway">
                                    <i class="fa <?= $isStripe ? 'fa-credit-card' : 'fa-store' ?>" style="color:<?= $isStripe ? '#635bff' : '#64748b' ?>;"></i>
                                    <?= $isStripe ? 'Stripe' : 'Manual' ?>
                                </span>
                            </td>
                            <td>
                                <div class="assin-actions">
                                    <button type="button" class="btn-action text-btn btn-primary-action" data-modal-target="#modal-cliente-detalhes" data-id="<?= htmlspecialchars($l['cliente_id']) ?>" title="Ver histórico do cliente">
                                        <i class="fa fa-list-alt"></i>
                                    </button>
                                    <?php if ($whatsapp !== ''): ?>
                                        <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-whatsapp" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                    <?php endif; ?>
                                    <?php if ($l['situacao'] === 'ativa'): ?>
                                        <a href="?action=cancelar_assinatura&cliente_id=<?= urlencode($l['cliente_id']) ?>&origem=assinaturas&tab=assinaturas&csrf_token=<?= urlencode($csrf_token) ?>"
                                           class="btn-action btn-warning"
                                           onclick="return confirm('Cancelar a assinatura de <?= htmlspecialchars(addslashes($l['nome_cliente'])) ?>? Não haverá novas cobranças e os benefícios seguem até <?= date('d/m/Y', strtotime($l['data_fim'])) ?>.');"
                                           title="Cancelar assinatura"><i class="fa fa-ban"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

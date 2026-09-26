<?php
// admin_tabs/avaliacoes.php — Hub de Avaliações (reformulado)
$csrf_token = generate_csrf_token();

$totalSentimentos = $sentimentoGeral['positivo_count'] + $sentimentoGeral['neutro_count'] + $sentimentoGeral['negativo_count'];
$percentualPositivo = ($totalSentimentos > 0) ? round(($sentimentoGeral['positivo_count'] / $totalSentimentos) * 100) : 0;

$respRA = $respostasAvaliacoesArr ?? [];
$totalAvGeral = count($avaliacoesArr);
$respondidas = 0; $negativasSemResposta = 0;
foreach ($avaliacoesArr as $avR) {
    $temResp = !empty($respRA[$avR['id'] ?? '']);
    if ($temResp) $respondidas++;
    if ((int)($avR['rating'] ?? 5) <= 2 && !$temResp) $negativasSemResposta++;
}
$taxaResposta = $totalAvGeral > 0 ? round($respondidas / $totalAvGeral * 100) : 0;

// Tendência: média por mês (últimos 6 meses)
$trendAgg = [];
foreach ($avaliacoesArr as $av) {
    $ym = substr($av['timestamp'] ?? '', 0, 7);
    if ($ym === '') continue;
    $trendAgg[$ym]['s'] = ($trendAgg[$ym]['s'] ?? 0) + (int)$av['rating'];
    $trendAgg[$ym]['c'] = ($trendAgg[$ym]['c'] ?? 0) + 1;
}
$trendLabels = []; $trendData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i month"));
    $trendLabels[] = date('m/y', strtotime($ym . '-01'));
    $trendData[] = (isset($trendAgg[$ym]) && $trendAgg[$ym]['c'] > 0) ? round($trendAgg[$ym]['s'] / $trendAgg[$ym]['c'], 2) : null;
}

// Filtros ativos (para preservar em paginação/links)
$filtrosAtivos = [
    'tab' => 'avaliacoes',
    'filtro_barbeiro_av' => $filtro_barbeiro_av,
    'filtro_nota_av' => $filtro_nota_av,
    'filtro_extra_av' => $filtro_extra_av ?? '',
    'filtro_data_ini_av' => $filtro_data_ini_av ?? '',
    'filtro_data_fim_av' => $filtro_data_fim_av ?? '',
    'busca_av' => $busca_av ?? '',
];
$totalPendentes = count($agendamentosPendentesDeAvaliacao);
$pendentesComEmail = 0;
foreach ($agendamentosPendentesDeAvaliacao as $agP) {
    if (filter_var($agP['email'] ?? '', FILTER_VALIDATE_EMAIL)) $pendentesComEmail++;
}
?>

<style>
    .avaliacoes-layout { display:flex; flex-direction:column; gap:28px; animation: fadeIn .5s ease-out; }
    .reminder-alert-card { background: linear-gradient(to right,#fffbeb,#fef3c7); border:1px solid #fde68a; border-radius:20px; padding:22px 26px; box-shadow:0 8px 20px -5px rgba(245,158,11,.15); }
    .reminder-top { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
    .reminder-info h4 { margin:0 0 6px; color:#b45309; font-size:1.2rem; display:flex; align-items:center; gap:10px; font-weight:800; }
    .reminder-info p { margin:0; color:#92400e; font-size:.98rem; font-weight:500; }
    .btn-warning-submit { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; border:none; padding:13px 24px; border-radius:12px; font-weight:800; font-size:.95rem; cursor:pointer; transition:.3s; display:inline-flex; align-items:center; gap:9px; box-shadow:0 4px 15px rgba(217,119,6,.3); }
    .btn-warning-submit:hover:not(:disabled) { transform:translateY(-2px); filter:brightness(1.05); }
    .btn-link-toggle { background:transparent; border:none; color:#b45309; font-weight:700; cursor:pointer; font-size:.88rem; margin-top:12px; display:inline-flex; align-items:center; gap:6px; }
    .pend-list { margin-top:14px; display:none; flex-direction:column; gap:8px; max-height:320px; overflow-y:auto; }
    .pend-list.open { display:flex; }
    .pend-item { display:flex; align-items:center; gap:10px; padding:10px 12px; background:rgba(255,255,255,.7); border:1px solid #fde68a; border-radius:10px; }
    .pend-item .pi-info { flex:1; min-width:0; } .pend-item .pi-info strong { color:#92400e; font-size:.9rem; } .pend-item .pi-info small { color:#b45309; font-size:.75rem; }
    .pi-btn { width:34px; height:34px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; cursor:pointer; text-decoration:none; font-size:.9rem; }
    .pi-wa { background:#dcfce7; color:#16a34a; } .pi-mail { background:#e0f2fe; color:#0284c7; }

    .kpi-icon { box-shadow: inset 0 -3px 0 rgba(0,0,0,.1); }
    .icon-yellow { background:linear-gradient(135deg,#fde047,#f59e0b); color:#fff; } .icon-blue { background:linear-gradient(135deg,#38bdf8,#0ea5e9); color:#fff; }
    .icon-green { background:linear-gradient(135deg,#34d399,#10b981); color:#fff; } .icon-indigo { background:linear-gradient(135deg,#818cf8,#6366f1); color:#fff; }

    .ia-summary-card { background:linear-gradient(135deg,#f5f3ff,#ede9fe); border:1px solid #ddd6fe; border-radius:20px; padding:26px; box-shadow:0 10px 25px -5px rgba(139,92,246,.15); position:relative; overflow:hidden; }
    .ia-header-flex { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:14px; }
    .ia-header-flex h4 { margin:0; color:#4c1d95; font-size:1.3rem; font-weight:800; display:flex; align-items:center; gap:12px; }
    .btn-ia-generate { background:linear-gradient(135deg,#8b5cf6,#6d28d9); color:#fff; border:none; padding:11px 22px; border-radius:12px; font-weight:700; cursor:pointer; transition:.3s; display:inline-flex; align-items:center; gap:9px; box-shadow:0 4px 15px rgba(139,92,246,.3); }
    .btn-ia-generate:hover { transform:translateY(-2px); filter:brightness(1.05); }
    .btn-ia-generate:disabled { opacity:.7; cursor:not-allowed; transform:none; }
    .ia-content-box { background:rgba(255,255,255,.8); border-radius:14px; padding:22px; color:#4c1d95; line-height:1.7; border:1px solid #e4e4e7; font-weight:500; }

    .chart-wrapper { position:relative; height:280px; width:100%; }

    .filter-bar { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px; border-bottom:2px solid #e2e8f0; padding-bottom:14px; }
    .filter-bar h3 { margin:0; color:#0f172a; font-size:1.35rem; font-weight:800; display:flex; align-items:center; gap:10px; }
    .modern-filter-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .ff-group { display:flex; flex-direction:column; gap:4px; }
    .ff-group label { font-size:.72rem; font-weight:700; color:#94a3b8; text-transform:uppercase; }
    .modern-filter-form .modern-select, .modern-filter-form input { height:42px; background:#fff; font-weight:600; border:1px solid #e2e8f0; border-radius:9px; padding:0 12px; font-size:.9rem; }
    .btn-clear { background:#f8fafc; color:#475569; padding:0 16px; height:42px; display:inline-flex; align-items:center; gap:6px; border-radius:9px; text-decoration:none; font-weight:700; font-size:.9rem; border:1px solid #e2e8f0; }
    .btn-clear:hover { background:#e2e8f0; color:#0f172a; }
    .btn-export { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; padding:0 16px; height:42px; display:inline-flex; align-items:center; gap:7px; border-radius:9px; text-decoration:none; font-weight:700; font-size:.9rem; }
    .btn-export:hover { background:#d1fae5; }

    .modern-card { position:relative; }
    .modern-card.destacada { border:2px solid #fbbf24; background:linear-gradient(to bottom,#fffbeb,#fff); box-shadow:0 10px 20px rgba(245,158,11,.1); }
    .modern-card.destacada::before { content:'★ SITE'; position:absolute; top:15px; right:-25px; background:#f59e0b; color:#fff; font-size:.65rem; font-weight:800; padding:4px 25px; transform:rotate(45deg); letter-spacing:1px; }
    .modern-card-header { display:flex; justify-content:space-between; align-items:flex-start; }
    .card-client-name:hover { color:var(--secondary-color,#007bff); text-decoration:underline; }
    .card-client-sub { margin:0; font-size:.85rem; color:#64748b; font-weight:500; } .card-client-sub strong { color:#334155; }
    .card-rating { color:#f59e0b; font-size:1.2rem; letter-spacing:2px; }
    .modern-card-body { display:flex; flex-direction:column; gap:14px; }
    .review-services { font-size:.85rem; color:#64748b; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .review-services span { background:#f1f5f9; padding:5px 12px; border-radius:8px; font-weight:700; color:#475569; border:1px solid #e2e8f0; }
    .review-comment { font-size:1.05rem; color:#334155; line-height:1.6; font-style:italic; margin:0; }
    .review-response { background:#f8fafc; border-left:4px solid var(--secondary-color,#007bff); padding:16px; border-radius:0 12px 12px 0; margin-top:8px; position:relative; }
    .review-response strong { display:block; font-size:.85rem; color:#0f172a; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; font-weight:800; }
    .review-response p { margin:0 0 8px; font-size:.95rem; color:#475569; line-height:1.6; }
    .review-response small { font-size:.75rem; color:#94a3b8; font-weight:600; }
    .resp-del { position:absolute; top:12px; right:12px; background:transparent; border:none; color:#cbd5e1; cursor:pointer; font-size:.9rem; }
    .resp-del:hover { color:#dc2626; }
    .modern-card-footer { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .review-date { font-size:.85rem; color:#94a3b8; font-weight:700; }
    .action-buttons form { display:inline; margin:0; }

    .av-progress-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
    .av-progress-overlay.is-open { display:flex; }
    .av-progress-box { width:min(440px,100%); background:#fff; border-radius:18px; padding:32px 30px; text-align:center; box-shadow:0 30px 70px -20px rgba(0,0,0,.5); }
    .av-progress-box h3 { margin:0 0 6px; font-size:1.3rem; color:#0f172a; font-weight:800; } .av-progress-box p { margin:0 0 18px; color:#64748b; font-size:.9rem; }
    .av-bar { height:12px; background:#e2e8f0; border-radius:999px; overflow:hidden; margin-bottom:12px; } .av-bar span { display:block; height:100%; width:0; background:linear-gradient(90deg,#f59e0b,#d97706); transition:width .3s; }
    .av-progress-stats { font-size:.9rem; color:#475569; font-weight:600; } .av-progress-stats b { color:#0f172a; }

    @media (max-width:768px) {
        .filter-bar { flex-direction:column; align-items:stretch; }
        .modern-filter-form { flex-direction:column; align-items:stretch; }
        .modern-filter-form .modern-select, .modern-filter-form input, .btn-clear, .btn-export { width:100%; }
        .reminder-top { flex-direction:column; text-align:center; }
    }
</style>

<div class="avaliacoes-layout">

    <div class="dash-header-bar">
        <div class="dash-title">
            <h3><i class="fa fa-star"></i> Hub de Avaliações</h3>
            <p>Monitore o feedback, responda aos clientes e destaque as melhores experiências.</p>
        </div>
    </div>

    <?php if ($totalPendentes > 0): ?>
    <div class="reminder-alert-card">
        <div class="reminder-top">
            <div class="reminder-info">
                <h4><i class="fa fa-bullhorn"></i> Feedback Pendente</h4>
                <p><strong><?= $totalPendentes ?> atendimento(s) concluído(s)</strong> aguardando avaliação — <strong><?= $pendentesComEmail ?> com e-mail</strong> para envio automático. O restante pode ser lembrado por WhatsApp abaixo. Não reenvia para quem já foi lembrado nos últimos 15 dias.</p>
            </div>
            <?php if ($pendentesComEmail > 0): ?>
                <button type="button" class="btn-warning-submit" onclick="avDispararLembretes()"><i class="fa fa-paper-plane"></i> Disparar por E-mail (<?= $pendentesComEmail ?>)</button>
            <?php else: ?>
                <button type="button" class="btn-warning-submit" style="opacity:.55; cursor:not-allowed;" onclick="Swal.fire('Sem e-mail','Os pendentes não têm e-mail cadastrado. Use o WhatsApp na lista individual abaixo.','info')"><i class="fa fa-paper-plane"></i> Disparar por E-mail</button>
            <?php endif; ?>
        </div>
        <button type="button" class="btn-link-toggle" onclick="document.getElementById('pend-list').classList.toggle('open')"><i class="fa fa-list"></i> Ver / enviar individualmente</button>
        <div class="pend-list" id="pend-list">
            <?php foreach ($agendamentosPendentesDeAvaliacao as $ag):
                $tel = limparTelefone($ag['telefone'] ?? '');
                $link = avaliacaoLink($ag['id']);
                $wa = 'https://wa.me/55' . $tel . '?text=' . urlencode('Olá ' . ($ag['nome'] ?? '') . '! Que tal avaliar seu último atendimento? É rapidinho: ' . $link);
            ?>
                <div class="pend-item">
                    <div class="pi-info">
                        <strong><?= htmlspecialchars($ag['nome'] ?? 'Cliente') ?></strong><br>
                        <small><?= date('d/m/Y', strtotime($ag['data'])) ?> · <?= htmlspecialchars($barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'N/A') ?></small>
                    </div>
                    <?php if ($tel !== ''): ?><a href="<?= htmlspecialchars($wa) ?>" target="_blank" class="pi-btn pi-wa" title="Enviar no WhatsApp"><i class="fab fa-whatsapp"></i></a><?php endif; ?>
                    <?php if (filter_var($ag['email'] ?? '', FILTER_VALIDATE_EMAIL)): ?>
                        <button type="button" class="pi-btn pi-mail" title="Enviar e-mail de avaliação" onclick="avLembreteIndividual('<?= htmlspecialchars($ag['id']) ?>', this)"><i class="fa fa-envelope"></i></button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-icon icon-yellow"><i class="fa fa-star"></i></div><div class="kpi-info"><h4>Média da Barbearia</h4><div class="value"><?= $media_geral_avaliacoes ?> <span style="font-size:1.2rem; color:#94a3b8; font-weight:700;">/ 5</span></div></div></div>
        <div class="kpi-card"><div class="kpi-icon" style="background:#fef2f2; color:#dc2626;"><i class="fa fa-triangle-exclamation"></i></div><div class="kpi-info"><h4>Negativas sem resposta</h4><div class="value" style="<?= $negativasSemResposta > 0 ? 'color:#dc2626;' : '' ?>"><?= (int)$negativasSemResposta ?></div></div></div>
        <div class="kpi-card"><div class="kpi-icon icon-indigo"><i class="fa fa-reply-all"></i></div><div class="kpi-info"><h4>Taxa de resposta</h4><div class="value"><?= $taxaResposta ?>%</div></div></div>
        <div class="kpi-card"><div class="kpi-icon icon-blue"><i class="fa fa-comment-dots"></i></div><div class="kpi-info"><h4>Total Recebido</h4><div class="value"><?= $totalAvGeral ?></div></div></div>
        <div class="kpi-card"><div class="kpi-icon icon-green"><i class="fa fa-smile-beam"></i></div><div class="kpi-info"><h4>Satisfação</h4><div class="value"><?= $percentualPositivo ?>%</div></div></div>
    </div>

    <div class="ia-summary-card">
        <div class="ia-header-flex">
            <h4><i class="fa fa-brain"></i> Análise de Inteligência Artificial</h4>
            <button id="btn-gerar-resumo" class="btn-ia-generate"><i class="fa fa-magic"></i> Gerar Insights Agora</button>
        </div>
        <div id="ia-summary-text" class="ia-content-box">
            <i class="fa fa-info-circle" style="color:#8b5cf6;"></i> Clique para a <strong>IA (Groq)</strong> analisar os comentários recentes e apontar elogios e pontos de atenção.
        </div>
    </div>

    <div class="widget-grid">
        <div class="widget-card"><div class="widget-header"><i class="fa fa-chart-pie"></i> Distribuição de Sentimento</div><div class="chart-wrapper"><canvas id="sentimentoGeralChart"></canvas></div></div>
        <div class="widget-card"><div class="widget-header"><i class="fa fa-chart-bar"></i> Desempenho da Equipe</div><div class="chart-wrapper"><canvas id="mediaPorBarbeiroChart"></canvas></div></div>
        <div class="widget-card"><div class="widget-header"><i class="fa fa-chart-line"></i> Evolução da Média (6 meses)</div><div class="chart-wrapper"><canvas id="tendenciaMediaChart"></canvas></div></div>
    </div>

    <div class="filter-bar">
        <h3><i class="fa fa-stream"></i> Feedbacks</h3>
        <form method="GET" class="modern-filter-form">
            <input type="hidden" name="tab" value="avaliacoes">
            <div class="ff-group"><label>Profissional</label>
                <select name="filtro_barbeiro_av" class="modern-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($barbeirosArr as $id => $b): ?><option value="<?= $id ?>" <?= $filtro_barbeiro_av == $id ? 'selected' : '' ?>><?= htmlspecialchars($b['nome']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ff-group"><label>Nota</label>
                <select name="filtro_nota_av" class="modern-select" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $filtro_nota_av == $i ? 'selected' : '' ?>><?= $i ?> ★</option><?php endfor; ?>
                </select>
            </div>
            <div class="ff-group"><label>Filtro rápido</label>
                <select name="filtro_extra_av" class="modern-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="com_comentario" <?= ($filtro_extra_av ?? '') === 'com_comentario' ? 'selected' : '' ?>>Com comentário</option>
                    <option value="sem_resposta" <?= ($filtro_extra_av ?? '') === 'sem_resposta' ? 'selected' : '' ?>>Sem resposta</option>
                    <option value="negativas" <?= ($filtro_extra_av ?? '') === 'negativas' ? 'selected' : '' ?>>Negativas (≤2★)</option>
                </select>
            </div>
            <div class="ff-group"><label>De</label><input type="date" name="filtro_data_ini_av" value="<?= htmlspecialchars($filtro_data_ini_av ?? '') ?>" onchange="this.form.submit()"></div>
            <div class="ff-group"><label>Até</label><input type="date" name="filtro_data_fim_av" value="<?= htmlspecialchars($filtro_data_fim_av ?? '') ?>" onchange="this.form.submit()"></div>
            <div class="ff-group"><label>Buscar</label><input type="text" name="busca_av" value="<?= htmlspecialchars($busca_av ?? '') ?>" placeholder="cliente ou texto..."></div>
            <a href="?tab=avaliacoes" class="btn-clear"><i class="fa fa-eraser"></i> Limpar</a>
            <a href="admin.php?action=exportar_avaliacoes&csrf_token=<?= $csrf_token ?>" class="btn-export"><i class="fa fa-file-csv"></i> Exportar</a>
        </form>
    </div>

    <div class="modern-card-grid">
        <?php if (empty($avaliacoesPaginados)): ?>
            <div class="empty-state"><i class="fa fa-comment-slash"></i><h3 style="margin:0 0 10px; color:#0f172a; font-weight:800;">Nenhum feedback encontrado</h3><p style="margin:0;">Ajuste os filtros ou aguarde novas avaliações.</p></div>
        <?php else: foreach ($avaliacoesPaginados as $av_id => $av):
            $cliente = $clientesArr[$av['cliente_id']] ?? null;
            $foto_cliente = ($cliente && !empty($cliente['foto_perfil']) && file_exists($cliente['foto_perfil'])) ? $cliente['foto_perfil'] : 'uploads/default-profile.jpg';
            $agendamento_original = $agendamentosArr[$av['agendamento_id']] ?? null;
            $servicosNomes = [];
            if ($agendamento_original) {
                $servicosNomes = array_map(function ($sid) use ($servicosArr, $combosArr) {
                    $sid = trim($sid);
                    if (isset($servicosArr[$sid])) return $servicosArr[$sid]['nome'];
                    elseif (isset($combosArr[$sid])) return $combosArr[$sid]['nome'] . ' (Combo)';
                    return '?';
                }, explode(',', $agendamento_original['servicos_ids']));
            }
            $is_destacada = isset($avaliacoesDestacadasArr[$av_id]);
            $resposta = $respRA[$av_id] ?? null;
        ?>
        <div class="modern-card <?= $is_destacada ? 'destacada' : '' ?>">
            <div class="modern-card-header">
                <div class="card-client-info">
                    <img src="<?= htmlspecialchars($foto_cliente) ?>" alt="Avatar" class="card-avatar" onerror="this.src='uploads/default-profile.jpg';">
                    <div>
                        <a href="#" class="card-client-name" data-modal-target="#modal-cliente-detalhes" data-id="<?= htmlspecialchars($av['cliente_id']) ?>"><?= htmlspecialchars($cliente['nome'] ?? 'Cliente Removido') ?></a>
                        <p class="card-client-sub">avaliou <strong><?= htmlspecialchars($barbeirosArr[$av['barbeiro_id']]['nome'] ?? 'N/A') ?></strong></p>
                    </div>
                </div>
                <div class="card-rating" title="Nota: <?= (int)$av['rating'] ?>/5"><?= str_repeat('★', (int)$av['rating']) . str_repeat('☆', 5 - (int)$av['rating']) ?></div>
            </div>
            <div class="modern-card-body">
                <?php if (!empty($servicosNomes)): ?><div class="review-services"><i class="fa fa-cut"></i><?php foreach ($servicosNomes as $sv): ?><span><?= htmlspecialchars($sv) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if (!empty($av['comment'])): ?><p class="review-comment">"<?= htmlspecialchars($av['comment']) ?>"</p><?php else: ?><p class="review-comment" style="color:#94a3b8; font-style:normal;"><i class="fa fa-info-circle"></i> Apenas nota, sem comentário.</p><?php endif; ?>
                <?php if ($resposta): ?>
                    <div class="review-response">
                        <form method="POST" action="admin.php" onsubmit="return confirm('Remover esta resposta?');">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="excluir_resposta_avaliacao"><input type="hidden" name="id" value="<?= $av_id ?>"><input type="hidden" name="tab" value="avaliacoes">
                            <button type="submit" class="resp-del" title="Remover resposta"><i class="fa fa-times"></i></button>
                        </form>
                        <strong><i class="fa fa-reply-all"></i> Resposta da Barbearia</strong>
                        <p><?= nl2br(htmlspecialchars($resposta['texto_resposta'])) ?></p>
                        <small><i class="fa fa-check-double"></i> Enviada em <?= date('d/m/Y', strtotime($resposta['timestamp'])) ?></small>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modern-card-footer">
                <span class="review-date"><i class="fa fa-clock"></i> <?= date('d/m/Y \à\s H:i', strtotime($av['timestamp'])) ?></span>
                <div class="action-buttons">
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="toggle_destaque_avaliacao"><input type="hidden" name="id" value="<?= $av_id ?>"><input type="hidden" name="tab" value="avaliacoes">
                        <button type="submit" class="btn-action <?= $is_destacada ? 'btn-warning text-btn' : 'btn-light btn-action-icon' ?>" title="<?= $is_destacada ? 'Remover destaque' : 'Destacar na Landing' ?>"><i class="fa fa-star"></i> <?= $is_destacada ? 'Destacado' : '' ?></button>
                    </form>
                    <button class="btn-action text-btn <?= $resposta ? 'btn-dark' : 'btn-primary-action' ?>" data-modal-target="#modal-responder-avaliacao" data-id_avaliacao="<?= $av_id ?>" data-resposta_existente="<?= htmlspecialchars($resposta['texto_resposta'] ?? '') ?>" data-review_text="<?= htmlspecialchars($av['comment'] ?? '') ?>" data-review_rating="<?= htmlspecialchars($av['rating'] ?? '') ?>"><i class="fa <?= $resposta ? 'fa-pen' : 'fa-reply' ?>"></i> <?= $resposta ? 'Editar' : 'Responder' ?></button>
                    <?php if ($agendamento_original): ?><button class="btn-action btn-action-icon btn-dark" data-modal-target="#modal-ver-agendamento" data-agendamento-id="<?= $av['agendamento_id'] ?>" title="Ver Agendamento"><i class="fa fa-file-invoice"></i></button><?php endif; ?>
                    <form method="POST" action="admin.php" onsubmit="return confirm('ATENÇÃO: apagar esta avaliação permanentemente?');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="excluir_avaliacao"><input type="hidden" name="id" value="<?= $av_id ?>"><input type="hidden" name="tab" value="avaliacoes">
                        <button type="submit" class="btn-action btn-action-icon btn-danger" title="Apagar"><i class="fa fa-trash-alt"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($avaliacoes_totalPages > 1): ?>
    <div class="modern-pagination">
        <?php for ($i = 1; $i <= $avaliacoes_totalPages; $i++): ?>
            <a href="?<?= http_build_query($filtrosAtivos + ['av_page' => $i]) ?>" class="<?= ($i == $avaliacoes_currentPage ? 'current' : '') ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div class="av-progress-overlay" id="av-progress">
    <div class="av-progress-box">
        <h3 id="av-prog-title">Enviando lembretes...</h3><p>Não feche esta janela até concluir.</p>
        <div class="av-bar"><span id="av-prog-bar"></span></div>
        <div class="av-progress-stats"><b id="av-prog-done">0</b> de <b id="av-prog-total">0</b> · <span id="av-prog-fail">0</span> falha(s)</div>
    </div>
</div>

<script>
const AV_CSRF = '<?= $csrf_token ?>';
const avTrendLabels = <?= json_encode($trendLabels) ?>;
const avTrendData = <?= json_encode($trendData) ?>;

// --- Gráfico de tendência (self-contained) ---
document.addEventListener('DOMContentLoaded', function () {
    const cv = document.getElementById('tendenciaMediaChart');
    if (cv && window.Chart) {
        new Chart(cv, {
            type: 'line',
            data: { labels: avTrendLabels, datasets: [{ label: 'Média', data: avTrendData, borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,.12)', fill: true, tension: .35, spanGaps: true, pointRadius: 4, pointBackgroundColor: '#0ea5e9' }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 5, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
        });
    }

    const btnResumo = document.getElementById('btn-gerar-resumo');
    const contentBox = document.getElementById('ia-summary-text');
    if (btnResumo) {
        btnResumo.addEventListener('click', async function () {
            btnResumo.disabled = true; btnResumo.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Analisando...';
            contentBox.innerHTML = '<div style="text-align:center; padding:22px; color:#6d28d9;"><i class="fa fa-circle-notch fa-spin fa-2x"></i><br><strong>A IA está trabalhando...</strong></div>';
            try {
                const r = await fetch('ajax_gemini.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'resumo_geral' }) });
                const d = await r.json();
                if (d.success) contentBox.innerHTML = '<strong style="display:block; margin-bottom:12px; color:#4c1d95;"><i class="fa fa-check-circle"></i> Análise Concluída:</strong>' + d.resposta.replace(/\n/g, '<br>');
                else contentBox.innerHTML = '<span style="color:#ef4444; font-weight:bold;"><i class="fa fa-exclamation-triangle"></i> Erro: ' + d.error + '</span>';
            } catch (e) { contentBox.innerHTML = '<span style="color:#ef4444; font-weight:bold;">Erro de conexão com a IA.</span>'; }
            finally { btnResumo.disabled = false; btnResumo.innerHTML = '<i class="fa fa-sync-alt"></i> Atualizar Insights'; }
        });
    }

    // --- Gerar resposta com IA (dentro do modal de resposta) ---
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('#btn_ia_responder_av');
        if (!btn) return;
        const rating = document.getElementById('modal_review_rating_hidden').value;
        const comment = document.getElementById('modal_review_text_hidden').value;
        const ta = document.getElementById('modal_texto_resposta');
        const original = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Gerando...';
        try {
            const r = await fetch('ajax_gemini.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'responder', nota: rating, comentario: comment }) });
            const d = await r.json();
            if (d.success) ta.value = d.resposta;
            else Swal.fire('Erro', d.error || 'Falha ao gerar.', 'error');
        } catch (err) { Swal.fire('Erro', 'Falha de comunicação com a IA.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = original; }
    });
});

// --- Envio de lembretes em lote (com progresso) ---
function avProgress(show) { document.getElementById('av-progress').classList.toggle('is-open', show); }
function avSetProgress(done, total, fail) {
    document.getElementById('av-prog-done').textContent = done;
    document.getElementById('av-prog-total').textContent = total;
    document.getElementById('av-prog-fail').textContent = fail;
    document.getElementById('av-prog-bar').style.width = (total > 0 ? Math.round(done / total * 100) : 100) + '%';
}
function avDispararLembretes() {
    Swal.fire({ title: 'Disparar lembretes?', text: 'Enviaremos um e-mail com link direto de avaliação aos clientes pendentes.', icon: 'question', showCancelButton: true, confirmButtonText: 'Sim, enviar', cancelButtonText: 'Cancelar' })
        .then(async res => {
            if (!res.isConfirmed) return;
            const fd = new FormData(); fd.append('action', 'lembrete_iniciar'); fd.append('csrf_token', AV_CSRF);
            let r;
            try { r = await fetch('admin_actions.php', { method: 'POST', body: fd }).then(x => x.json()); } catch (e) { Swal.fire('Erro', 'Falha de comunicação.', 'error'); return; }
            if (!r.success) { Swal.fire('Atenção', r.error || 'Não foi possível iniciar.', 'warning'); return; }
            const id = r.campanha_id, total = r.total;
            avSetProgress(0, total, 0); avProgress(true);
            let done = false, enviados = 0, falhas = 0;
            while (!done) {
                const fl = new FormData(); fl.append('action', 'campanha_lote'); fl.append('csrf_token', AV_CSRF); fl.append('campanha_id', id);
                let lr; try { lr = await fetch('admin_actions.php', { method: 'POST', body: fl }).then(x => x.json()); } catch (e) { avProgress(false); Swal.fire('Erro', 'Falha durante o envio.', 'error'); return; }
                if (!lr.success) { avProgress(false); Swal.fire('Erro', lr.error || 'Falha no lote.', 'error'); return; }
                enviados = lr.enviados; falhas = lr.falhas; done = lr.done; avSetProgress(enviados + falhas, total, falhas);
            }
            avProgress(false);
            await Swal.fire('Concluído!', enviados + ' lembrete(s) enviados' + (falhas ? (' · ' + falhas + ' falha(s)') : '') + '.', 'success');
            window.location = 'admin.php?tab=avaliacoes';
        });
}
function avLembreteIndividual(agId, btn) {
    const original = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    const fd = new FormData(); fd.append('action', 'lembrete_individual'); fd.append('csrf_token', AV_CSRF); fd.append('agendamento_id', agId);
    fetch('admin_actions.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (d.success) { btn.innerHTML = '<i class="fa fa-check"></i>'; btn.style.background = '#dcfce7'; btn.style.color = '#16a34a'; }
        else { btn.disabled = false; btn.innerHTML = original; Swal.fire('Erro', d.error || 'Falha ao enviar.', 'error'); }
    }).catch(() => { btn.disabled = false; btn.innerHTML = original; Swal.fire('Erro', 'Falha de comunicação.', 'error'); });
}
</script>

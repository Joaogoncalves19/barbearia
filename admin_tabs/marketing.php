<?php
// admin_tabs/marketing.php — Marketing & Retenção (reformulado)
$iaConfigurada = function_exists('iaTemChaveConfigurada') ? iaTemChaveConfigurada() : false;
$csrf_token = generate_csrf_token();

// Garante colunas/tabelas novas e recarrega cupons/vouchers com os campos extras
if (function_exists('garantirColunasMarketing')) { garantirColunasMarketing(); }
if (function_exists('marketingGarantirTabelas')) { marketingGarantirTabelas(); }
$cupoesArr   = function_exists('cupomKeys')   ? lerDados('cupoes', cupomKeys())     : ($cupoesArr ?? []);
$vouchersArr = function_exists('voucherKeys') ? lerDados('vouchers', voucherKeys()) : ($vouchersArr ?? []);

$hoje = date('Y-m-d');

// --- Métricas do mini-dashboard ---
$cuponsAtivos = array_filter($cupoesArr, function ($c) use ($hoje) {
    return (int)($c['ativo'] ?? 1) === 1
        && (($c['data_validade'] ?? '') === '' || $c['data_validade'] >= $hoje)
        && (int)($c['usos_atuais'] ?? 0) < (int)($c['usos_maximos'] ?? 0);
});
$vouchersDisp = array_filter($vouchersArr, fn($v) => ($v['status'] ?? '') === 'disponivel');
$somaVouchers = array_sum(array_map(fn($v) => (float)($v['valor'] ?? 0), $vouchersDisp));

$campEstat = ['tot' => 0, 'emails' => 0];
$totOptout = 0;
try {
    $r = getDB()->query("SELECT COUNT(*) c, COALESCE(SUM(enviados),0) e FROM campanhas")->fetch(PDO::FETCH_ASSOC);
    $campEstat = ['tot' => (int)$r['c'], 'emails' => (int)$r['e']];
    $totOptout = (int) getDB()->query("SELECT COUNT(*) FROM email_optout")->fetchColumn();
} catch (Exception $e) {}

$campanhasRecentes = function_exists('marketingCampanhasRecentes') ? marketingCampanhasRecentes(6) : [];

// Barbeiros ativos para o segmento "por barbeiro"
$barbeirosParaSegmento = array_filter($barbeirosArr ?? [], fn($b) => ($b['status'] ?? 'ativo') !== 'inativo');
?>

<style>
    .marketing-layout { display:flex; flex-direction:column; gap:25px; animation: fadeIn 0.4s ease-out; }
    .marketing-grid-2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(360px,1fr)); gap:25px; }
    .widget-card.full-width { grid-column:1 / -1; }
    .widget-desc { font-size:0.9rem; color:#64748b; margin-bottom:22px; line-height:1.5; }

    /* Mini-dashboard */
    .mkt-metrics { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:16px; }
    .mkt-metric { background:#fff; border:1px solid var(--admin-border,#e2e8f0); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 10px 30px -24px rgba(15,23,42,.7); }
    .mkt-metric i { width:46px; height:46px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; border-radius:12px; font-size:1.2rem; }
    .mkt-metric .mm-val { font-size:1.5rem; font-weight:800; color:#0f172a; line-height:1.1; }
    .mkt-metric .mm-lbl { font-size:.8rem; color:#64748b; font-weight:600; }
    .mm-blue i { background:#eff6ff; color:#2563eb; } .mm-green i { background:#ecfdf5; color:#059669; }
    .mm-purple i { background:#f5f3ff; color:#7c3aed; } .mm-amber i { background:#fffbeb; color:#d97706; }
    .mm-slate i { background:#f1f5f9; color:#475569; }

    /* Formulário / segmentação */
    .modern-form label { font-weight:600; color:#475569; margin-bottom:8px; font-size:0.95rem; display:block; }
    .mkt-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .mkt-row > .form-group { flex:1; min-width:200px; margin:0; }
    .mkt-count-badge { display:inline-flex; align-items:center; gap:7px; padding:5px 12px; border-radius:999px; background:var(--admin-accent-soft,#eff6ff); color:var(--admin-accent-strong,#2563eb); font-weight:700; font-size:.85rem; border:1px solid var(--admin-accent-border,#bfdbfe); }
    .tags-container { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .tag-item { background:#f1f5f9; border:1px solid #cbd5e1; padding:4px 9px; border-radius:6px; font-size:0.8rem; color:#475569; cursor:pointer; transition:.15s; font-family:ui-monospace,monospace; }
    .tag-item:hover { background:#e2e8f0; color:#1e293b; }

    .btn-modern-submit { background:var(--secondary-color,#007bff); color:#fff; border:none; padding:14px 22px; border-radius:10px; font-weight:700; font-size:1rem; cursor:pointer; transition:.2s; display:inline-flex; align-items:center; justify-content:center; gap:9px; box-shadow:0 4px 10px rgba(0,123,255,.2); }
    .btn-modern-submit:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 6px 15px rgba(0,123,255,.3); }
    .btn-modern-submit:disabled { background:#cbd5e1; cursor:not-allowed; box-shadow:none; transform:none; }
    .btn-warning-submit { background:#f59e0b; box-shadow:0 4px 10px rgba(245,158,11,.2); }
    .btn-warning-submit:hover:not(:disabled) { background:#d97706; }
    .btn-soft { background:#f8fafc; color:#475569; border:1px solid #e2e8f0; padding:12px 18px; border-radius:10px; font-weight:600; cursor:pointer; transition:.2s; display:inline-flex; align-items:center; gap:8px; }
    .btn-soft:hover { border-color:var(--secondary-color); color:var(--secondary-color); }
    .btn-ia-discreto { background:#f8fafc; color:#6d28d9; border:1px solid #ddd6fe; padding:8px 16px; border-radius:8px; font-weight:700; font-size:0.9rem; cursor:pointer; transition:.2s; display:inline-flex; align-items:center; gap:8px; }
    .btn-ia-discreto:hover { background:#f5f3ff; border-color:#8b5cf6; }
    .btn-inline-add { background:var(--secondary-color,#007bff); color:#fff; padding:8px 14px; border-radius:9px; font-weight:600; font-size:0.9rem; display:inline-flex; align-items:center; gap:7px; text-decoration:none; border:none; cursor:pointer; transition:.2s; white-space:nowrap; }
    .btn-inline-add:hover { transform:translateY(-1px); color:#fff; }
    .btn-info { background:#0ea5e9; }
    .mkt-actions-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:6px; }

    /* Progresso de envio */
    .mkt-progress-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
    .mkt-progress-overlay.is-open { display:flex; }
    .mkt-progress-box { width:min(440px,100%); background:#fff; border-radius:18px; padding:32px 30px; text-align:center; box-shadow:0 30px 70px -20px rgba(0,0,0,.5); }
    .mkt-progress-box h3 { margin:0 0 6px; font-size:1.3rem; color:#0f172a; font-weight:800; }
    .mkt-progress-box p { margin:0 0 18px; color:#64748b; font-size:.92rem; }
    .mkt-bar { height:12px; background:#e2e8f0; border-radius:999px; overflow:hidden; margin-bottom:12px; }
    .mkt-bar span { display:block; height:100%; width:0; background:linear-gradient(90deg,#22c55e,#16a34a); transition:width .3s; }
    .mkt-progress-stats { font-size:.9rem; color:#475569; font-weight:600; }
    .mkt-progress-stats b { color:#0f172a; }

    /* Histórico de campanhas */
    .camp-hist { display:flex; flex-direction:column; gap:10px; }
    .camp-item { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid #eef2f7; border-radius:12px; background:#fff; }
    .camp-ic { width:38px; height:38px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; border-radius:10px; font-size:.95rem; }
    .camp-ic.newsletter { background:#eff6ff; color:#2563eb; } .camp-ic.reativacao { background:#fffbeb; color:#d97706; }
    .camp-info { flex:1; min-width:0; }
    .camp-info strong { display:block; color:#1e293b; font-size:.92rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .camp-info small { color:#94a3b8; font-size:.76rem; }
    .camp-badge { flex:0 0 auto; font-size:.74rem; font-weight:700; padding:4px 10px; border-radius:999px; }
    .camp-badge.ok { background:#ecfdf5; color:#059669; } .camp-badge.run { background:#eff6ff; color:#2563eb; }

    /* Tabelas / status */
    .badge-off { background:#f1f5f9; color:#94a3b8; }
    .cupom-code { font-family:ui-monospace,monospace; font-size:.9rem; background:#f1f5f9; padding:2px 7px; border-radius:5px; color:#1e293b; }

    .alert-box { padding:14px 18px; border-radius:10px; font-size:0.95rem; margin-bottom:18px; display:flex; gap:10px; align-items:center; font-weight:500; }
    .alert-box.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .alert-box.danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

    @media (max-width:768px) { .mkt-row { flex-direction:column; } }
</style>

<div class="marketing-layout">

    <div class="dash-header-bar">
        <div class="dash-title">
            <h3><i class="fa fa-bullhorn"></i> Marketing &amp; Retenção</h3>
            <p>Atraia novos clientes e aumente a frequência de visitas com campanhas, cupons e vouchers.</p>
        </div>
    </div>

    <!-- MINI-DASHBOARD -->
    <div class="mkt-metrics">
        <div class="mkt-metric mm-blue"><i class="fa fa-paper-plane"></i><div><div class="mm-val"><?= (int)$campEstat['emails'] ?></div><div class="mm-lbl">E-mails enviados</div></div></div>
        <div class="mkt-metric mm-slate"><i class="fa fa-rectangle-list"></i><div><div class="mm-val"><?= (int)$campEstat['tot'] ?></div><div class="mm-lbl">Campanhas criadas</div></div></div>
        <div class="mkt-metric mm-purple"><i class="fa fa-ticket"></i><div><div class="mm-val"><?= count($cuponsAtivos) ?></div><div class="mm-lbl">Cupons ativos</div></div></div>
        <div class="mkt-metric mm-green"><i class="fa fa-gift"></i><div><div class="mm-val"><?= count($vouchersDisp) ?></div><div class="mm-lbl">Vouchers (R$ <?= number_format($somaVouchers, 2, ',', '.') ?>)</div></div></div>
        <div class="mkt-metric mm-amber"><i class="fa fa-user-slash"></i><div><div class="mm-val"><?= (int)$totOptout ?></div><div class="mm-lbl">Descadastrados</div></div></div>
    </div>

    <!-- NEWSLETTER -->
    <div class="widget-card full-width">
        <div class="widget-header" style="border-bottom:none; padding-bottom:0;">
            <div><i class="fa fa-paper-plane"></i> Disparar Campanha de E-mail</div>
        </div>
        <p class="widget-desc">Envie novidades e promoções para um público segmentado. O envio é feito em lotes, com barra de progresso.</p>

        <div style="background:linear-gradient(135deg,#f5f3ff,#ede9fe); border:1px solid #ddd6fe; border-radius:12px; padding:14px 18px; margin-bottom:22px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <h4 style="margin:0 0 4px; color:#5b21b6; display:flex; align-items:center; gap:8px; font-size:1.02rem;"><i class="fa fa-magic"></i> Assistente de Campanhas (IA)</h4>
                <p style="margin:0; font-size:0.88rem; color:#4c1d95;">A IA (Groq) escreve o texto persuasivo em HTML para você.</p>
            </div>
            <button type="button" class="btn-ia-discreto" data-modal-target="#modal-ia-newsletter"><i class="fa fa-pen-nib"></i> Gerar Texto com IA</button>
        </div>

        <form class="modern-form" id="form-newsletter" onsubmit="return false;">
            <div class="mkt-row">
                <div class="form-group">
                    <label>Público Alvo</label>
                    <select id="nl_segmento" class="modern-select">
                        <option value="ativos">Clientes ativos</option>
                        <option value="todos">Todos os cadastrados</option>
                        <option value="aniversariantes">Aniversariantes do mês</option>
                        <option value="novos">Novos (ainda sem agendamento)</option>
                        <option value="sem_retorno">Sem retornar há X dias</option>
                        <option value="assinantes">Assinantes</option>
                        <option value="barbeiro">Clientes de um barbeiro</option>
                    </select>
                </div>
                <div class="form-group" id="nl_param_dias_wrap" style="display:none;">
                    <label>Dias sem retornar</label>
                    <select id="nl_dias" class="modern-select">
                        <option>30</option><option>60</option><option selected>90</option><option>120</option><option>180</option>
                    </select>
                </div>
                <div class="form-group" id="nl_param_barbeiro_wrap" style="display:none;">
                    <label>Barbeiro</label>
                    <select id="nl_barbeiro" class="modern-select">
                        <?php foreach ($barbeirosParaSegmento as $b): ?>
                            <option value="<?= htmlspecialchars($b['id']) ?>"><?= htmlspecialchars($b['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:0 0 auto; align-self:flex-end;">
                    <span class="mkt-count-badge"><i class="fa fa-users"></i> <span id="nl_count">—</span> destinatário(s)</span>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label>Assunto do E-mail</label>
                <input type="text" id="nl_assunto" class="modern-input" required placeholder="Ex: Novidade exclusiva para você!">
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label>Mensagem (suporta HTML)</label>
                <textarea id="nl_corpo" class="modern-textarea" rows="8" placeholder="Escreva sua mensagem ou use o botão de IA acima..." required></textarea>
                <div style="margin-top:9px;">
                    <span style="font-size:0.82rem; color:#64748b; font-weight:600;">Tags (clique para inserir):</span>
                    <div class="tags-container">
                        <span class="tag-item" onclick="mktInserirTag('{nome_cliente}')">{nome_cliente}</span>
                        <span class="tag-item" onclick="mktInserirTag('{primeiro_nome}')">{primeiro_nome}</span>
                        <span class="tag-item" onclick="mktInserirTag('{nome_barbearia}')">{nome_barbearia}</span>
                        <span class="tag-item" onclick="mktInserirTag('{link_agendamento}')">{link_agendamento}</span>
                    </div>
                </div>
            </div>

            <div class="mkt-actions-row">
                <button type="button" class="btn-soft" onclick="mktPreview()"><i class="fa fa-eye"></i> Pré-visualizar</button>
                <button type="button" class="btn-soft" onclick="mktTeste()"><i class="fa fa-vial"></i> Enviar teste</button>
                <button type="button" class="btn-modern-submit" style="margin-left:auto;" onclick="mktEnviarNewsletter()"><i class="fa fa-envelope-open-text"></i> Enviar campanha</button>
            </div>
        </form>
    </div>

    <div class="marketing-grid-2">
        <!-- WIN-BACK -->
        <div class="widget-card">
            <h4 class="widget-header"><i class="fa fa-user-clock"></i> Reativação (Win-back)</h4>
            <p class="widget-desc">Clientes recorrentes que sumiram. Reengaje com cupom por e-mail (não reenvia para quem já foi contatado nos últimos 15 dias) ou pelo WhatsApp.</p>

            <?php
            $opcoesDias = [30, 60, 90, 120, 180];
            $diasRisco = (int)($_GET['dias_risco'] ?? 90);
            if (!in_array($diasRisco, $opcoesDias, true)) $diasRisco = 90;
            $clientesEmRiscoLista = getClientesEmRisco($agendamentosArr ?? [], $clientesArr ?? [], $diasRisco);
            uasort($clientesEmRiscoLista, fn($a, $b) => strcmp($a['ultimo_agendamento'], $b['ultimo_agendamento']));
            $totalClientesEmRisco = count($clientesEmRiscoLista);
            $cupoesValidos = $cuponsAtivos;
            ?>

            <div class="risco-filtro">
                <span>Ausentes há mais de:</span>
                <?php foreach ($opcoesDias as $d): ?>
                    <a href="admin.php?tab=marketing&dias_risco=<?= $d ?>" class="risco-chip <?= $d === $diasRisco ? 'is-active' : '' ?>"><?= $d ?> dias</a>
                <?php endforeach; ?>
            </div>

            <?php if ($totalClientesEmRisco > 0): ?>
                <div class="alert-box danger" style="margin-bottom:14px;">
                    <i class="fa fa-exclamation-circle" style="font-size:1.3rem;"></i>
                    <strong><?= $totalClientesEmRisco ?> cliente(s)</strong> sem retornar há <?= $diasRisco ?>+ dias.
                </div>

                <div class="risco-lista">
                    <?php foreach ($clientesEmRiscoLista as $cli):
                        $diasSem = (int) floor((time() - strtotime($cli['ultimo_agendamento'])) / 86400);
                        $telLimpo = limparTelefone($cli['telefone'] ?? '');
                    ?>
                        <div class="risco-item">
                            <div class="risco-info">
                                <strong><?= htmlspecialchars($cli['nome']) ?></strong>
                                <small>Última visita <?= date('d/m/Y', strtotime($cli['ultimo_agendamento'])) ?> · há <?= $diasSem ?> dias</small>
                            </div>
                            <div style="display:flex; gap:6px; flex-shrink:0;">
                                <button type="button" class="risco-ia" title="Gerar mensagem com IA"
                                    data-nome="<?= htmlspecialchars($cli['nome'] ?? '') ?>" data-tel="<?= htmlspecialchars($telLimpo) ?>"
                                    data-dias="<?= $diasSem ?>" data-ultima="<?= htmlspecialchars(date('d/m/Y', strtotime($cli['ultimo_agendamento']))) ?>"><i class="fa fa-wand-magic-sparkles"></i></button>
                                <?php if ($telLimpo !== ''): ?>
                                    <a href="https://wa.me/55<?= $telLimpo ?>?text=<?= urlencode('Olá ' . ($cli['nome'] ?? '') . '! Sentimos sua falta na barbearia. Que tal marcar um horário?') ?>" target="_blank" class="risco-wa" title="Chamar no WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="modern-form" style="margin-top:14px;">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label>Enviar cupom por e-mail para todos acima</label>
                        <?php if (empty($cupoesValidos)): ?>
                            <p style="color:#dc2626; font-size:0.9rem; font-weight:600; margin:0;"><i class="fa fa-times"></i> Crie um cupom ativo antes. <a href="#" data-modal-target="#modal-cupom" style="color:var(--secondary-color);">Criar cupom</a></p>
                        <?php else: ?>
                            <select id="wb_cupom" class="modern-select">
                                <option value="">Selecione um cupom...</option>
                                <?php foreach ($cupoesValidos as $cupom): ?>
                                    <option value="<?= htmlspecialchars($cupom['id']) ?>"><?= htmlspecialchars($cupom['codigo']) ?> (<?= (($cupom['tipo_desconto'] ?? 'percentual') === 'fixo') ? ('R$ ' . number_format((float)$cupom['valor_desconto'], 2, ',', '.')) : ((int)$cupom['desconto_percentual'] . '% OFF') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-modern-submit btn-warning-submit" <?= empty($cupoesValidos) ? 'disabled' : '' ?> onclick="mktEnviarReativacao(<?= $diasRisco ?>)">
                        <i class="fa fa-paper-plane"></i> Enviar cupom aos clientes
                    </button>
                </div>
            <?php else: ?>
                <div class="alert-box success" style="margin-top:10px;">
                    <i class="fa fa-check-circle" style="font-size:1.5rem;"></i>
                    Ótima retenção! Nenhum cliente recorrente ausente há mais de <?= $diasRisco ?> dias.
                </div>
            <?php endif; ?>
        </div>

        <!-- HISTÓRICO + ANIVERSÁRIO -->
        <div style="display:flex; flex-direction:column; gap:25px;">
            <div class="widget-card">
                <h4 class="widget-header"><i class="fa fa-clock-rotate-left"></i> Campanhas Recentes</h4>
                <?php if (empty($campanhasRecentes)): ?>
                    <p class="widget-desc" style="margin:8px 0 0;">Nenhuma campanha enviada ainda.</p>
                <?php else: ?>
                    <div class="camp-hist" style="margin-top:6px;">
                        <?php foreach ($campanhasRecentes as $cmp):
                            $tipo = $cmp['tipo'] === 'reativacao' ? 'reativacao' : 'newsletter';
                            $concl = $cmp['status'] === 'concluida';
                        ?>
                            <div class="camp-item">
                                <div class="camp-ic <?= $tipo ?>"><i class="fa <?= $tipo === 'reativacao' ? 'fa-user-clock' : 'fa-paper-plane' ?>"></i></div>
                                <div class="camp-info">
                                    <strong><?= htmlspecialchars($cmp['assunto'] ?: 'Campanha') ?></strong>
                                    <small><?= date('d/m/Y H:i', strtotime($cmp['criada_em'])) ?> · <?= (int)$cmp['enviados'] ?>/<?= (int)$cmp['total'] ?> enviados<?= (int)$cmp['falhas'] > 0 ? ' · ' . (int)$cmp['falhas'] . ' falha(s)' : '' ?></small>
                                </div>
                                <span class="camp-badge <?= $concl ? 'ok' : 'run' ?>"><?= $concl ? 'Concluída' : 'Em envio' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="widget-card">
                <h4 class="widget-header"><i class="fa fa-birthday-cake"></i> Desconto de Aniversário</h4>
                <p class="widget-desc">Aplica o desconto automaticamente no primeiro agendamento do mês de aniversário do cliente.</p>
                <form method="POST" class="modern-form">
                    <input type="hidden" name="action" value="salvar_config_aniversario">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mkt-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="aniversario_ativado" class="modern-select">
                                <option value="1" <?= ($configAniversario['ativado'] ?? 0) == 1 ? 'selected' : '' ?>>Ativada</option>
                                <option value="0" <?= ($configAniversario['ativado'] ?? 0) == 0 ? 'selected' : '' ?>>Desativada</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Desconto (%)</label>
                            <input type="number" name="desconto_percentual" class="modern-input" value="<?= $configAniversario['desconto_percentual'] ?? 15 ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-modern-submit" style="padding:12px;"><i class="fa fa-save"></i> Salvar Regras</button>
                </form>
            </div>
        </div>
    </div>

    <div class="marketing-grid-2">
        <!-- CUPONS -->
        <div class="widget-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px; border-bottom:2px solid #f8fafc; padding-bottom:14px;">
                <h4 class="widget-header" style="border:none; padding:0; margin:0;"><i class="fa fa-tag"></i> Gestão de Cupons</h4>
                <button class="btn-inline-add" data-modal-target="#modal-cupom"><i class="fa fa-plus-circle"></i> Criar</button>
            </div>
            <div class="modern-table-wrapper" style="margin-top:0; border:none; box-shadow:none;">
                <table class="modern-table">
                    <thead><tr><th>Código</th><th>Desconto</th><th>Status</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php if (empty($cupoesArr)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#94a3b8; font-style:italic; padding:20px;">Nenhum cupom criado.</td></tr>
                        <?php else: foreach ($cupoesArr as $c):
                            $tipo = ($c['tipo_desconto'] ?? 'percentual');
                            $ativo = (int)($c['ativo'] ?? 1) === 1;
                            $vencido = (($c['data_validade'] ?? '') !== '' && $c['data_validade'] < $hoje);
                            $esgotado = ((int)($c['usos_atuais'] ?? 0) >= (int)($c['usos_maximos'] ?? 0));
                            $descTxt = $tipo === 'fixo' ? ('R$ ' . number_format((float)($c['valor_desconto'] ?? 0), 2, ',', '.')) : ((int)$c['desconto_percentual'] . '%');
                        ?>
                            <tr style="<?= (!$ativo || $vencido || $esgotado) ? 'opacity:.6;' : '' ?>">
                                <td data-label="Código"><span class="cupom-code"><?= htmlspecialchars($c['codigo']) ?></span></td>
                                <td data-label="Desconto"><span class="badge badge-green"><?= $descTxt ?></span></td>
                                <td data-label="Status">
                                    <?php if ($ativo): ?><span class="badge badge-green">Ativo</span><?php else: ?><span class="badge badge-off">Desativado</span><?php endif; ?>
                                    <?php if ($vencido): ?><span class="badge badge-off">Vencido</span><?php endif; ?>
                                </td>
                                <td data-label="Ações">
                                    <div class="action-buttons">
                                        <form method="POST" action="admin.php" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="toggle_cupom">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                                            <input type="hidden" name="tab" value="marketing">
                                            <button type="submit" class="btn-action <?= $ativo ? 'btn-dark' : 'btn-info' ?>" title="<?= $ativo ? 'Desativar' : 'Ativar' ?>"><i class="fa <?= $ativo ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i></button>
                                        </form>
                                        <button class="btn-action btn-dark" data-modal-target="#modal-cupom" data-id="<?= $c['id'] ?>" data-type="cupom"
                                            data-codigo="<?= htmlspecialchars($c['codigo']) ?>" data-desconto="<?= htmlspecialchars($c['desconto_percentual']) ?>"
                                            data-usos="<?= htmlspecialchars($c['usos_maximos']) ?>" data-validade="<?= htmlspecialchars($c['data_validade']) ?>"
                                            data-atuais="<?= htmlspecialchars($c['usos_atuais'] ?? 0) ?>" data-tipo="<?= htmlspecialchars($tipo) ?>"
                                            data-valor="<?= htmlspecialchars($c['valor_desconto'] ?? '') ?>" data-ativo="<?= $ativo ? '1' : '0' ?>" title="Editar"><i class="fa fa-edit"></i></button>
                                        <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Excluir este cupom?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="excluir_cupom">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                                            <input type="hidden" name="tab" value="marketing">
                                            <button type="submit" class="btn-action btn-danger" title="Excluir"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VOUCHERS -->
        <div class="widget-card">
            <h4 class="widget-header"><i class="fa fa-gift"></i> Vouchers de Presente</h4>
            <p class="widget-desc" style="margin-bottom:14px;">Gere gift cards pré-pagos com valor em Reais para os clientes presentearem.</p>

            <form method="POST" class="modern-form" style="background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:15px;">
                <input type="hidden" name="action" value="gerar_voucher">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="mkt-row" style="margin-bottom:12px;">
                    <div class="form-group"><label style="font-size:.85rem;">Valor (R$)</label><input type="number" name="valor_voucher" class="modern-input" step="0.01" min="1" required placeholder="50.00"></div>
                    <div class="form-group"><label style="font-size:.85rem;">Validade (opcional)</label><input type="date" name="data_validade_voucher" class="modern-input"></div>
                </div>
                <div class="form-group" style="margin-bottom:12px;"><label style="font-size:.85rem;">Comprador / destinatário (opcional)</label><input type="text" name="comprador" class="modern-input" placeholder="Nome de quem comprou/recebeu"></div>
                <button type="submit" class="btn-modern-submit" style="padding:10px 16px; font-size:.92rem;"><i class="fa fa-magic"></i> Gerar Voucher</button>
            </form>

            <?php
            $vouchersListagem = array_reverse($vouchersArr, true);
            $filtro_status_voucher = $_GET['filtro_status_voucher'] ?? '';
            if (!in_array($filtro_status_voucher, ['', 'disponivel', 'usado'], true)) $filtro_status_voucher = '';
            if ($filtro_status_voucher === 'disponivel') {
                $vouchersListagem = array_filter($vouchersListagem, fn($v) => ($v['status'] ?? '') === 'disponivel');
            } elseif ($filtro_status_voucher === 'usado') {
                $vouchersListagem = array_filter($vouchersListagem, fn($v) => ($v['status'] ?? '') !== 'disponivel');
            }
            $vpp = 8;
            $vtot = count($vouchersListagem);
            $vpages = $vtot > 0 ? (int)ceil($vtot / $vpp) : 1;
            $vpage = max(1, min((int)($_GET['vou_page'] ?? 1), $vpages));
            $vouchersPaginados = array_slice($vouchersListagem, ($vpage - 1) * $vpp, $vpp, true);
            ?>

            <div class="voucher-list-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:8px 0 4px; color:#64748b; font-size:.88rem;">
                <span><strong style="color:#1e293b;"><?= $vtot ?></strong> voucher(s)<?= $vpages > 1 ? ' · página ' . $vpage . ' de ' . $vpages : '' ?></span>
                <form method="GET" action="admin.php" style="margin:0;">
                    <input type="hidden" name="tab" value="marketing">
                    <select name="filtro_status_voucher" class="modern-select" style="width:auto; min-width:160px; height:38px; padding:0 12px; font-size:.85rem;" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="disponivel" <?= $filtro_status_voucher === 'disponivel' ? 'selected' : '' ?>>Disponíveis</option>
                        <option value="usado" <?= $filtro_status_voucher === 'usado' ? 'selected' : '' ?>>Já utilizados</option>
                    </select>
                </form>
            </div>

            <div class="modern-table-wrapper" style="border:none; box-shadow:none; margin:0;">
                <table class="modern-table">
                    <thead><tr><th>Código</th><th>Valor</th><th>Comprador</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php if (empty($vouchersPaginados)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#94a3b8; font-style:italic; padding:20px;"><?= $filtro_status_voucher !== '' ? 'Nenhum voucher com este status.' : 'Nenhum voucher no sistema.' ?></td></tr>
                        <?php else: foreach ($vouchersPaginados as $v): ?>
                            <tr style="<?= ($v['status'] ?? '') !== 'disponivel' ? 'opacity:.6;' : '' ?>">
                                <td data-label="Código">
                                    <span class="cupom-code" style="color:#6b21a8; background:#f3e8ff;"><?= htmlspecialchars($v['codigo']) ?></span>
                                    <?php if (!empty($v['data_validade'])): ?><br><small style="color:#94a3b8;">val. <?= date('d/m/Y', strtotime($v['data_validade'])) ?></small><?php endif; ?>
                                </td>
                                <td data-label="Valor"><strong style="color:#059669;">R$ <?= number_format((float)$v['valor'], 2, ',', '.') ?></strong></td>
                                <td data-label="Comprador"><?= !empty($v['comprador']) ? htmlspecialchars($v['comprador']) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                                <td data-label="Ações">
                                    <div class="action-buttons">
                                        <a href="imprimir_voucher.php?id=<?= $v['id'] ?>" target="_blank" class="btn-action btn-info" title="Imprimir PDF"><i class="fa fa-print"></i></a>
                                        <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Excluir este voucher?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="excluir_voucher">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($v['id']) ?>">
                                            <input type="hidden" name="tab" value="marketing">
                                            <button type="submit" class="btn-action btn-danger" title="Excluir"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($vpages > 1): ?>
                <div class="modern-pagination" style="margin:14px 0 0;">
                    <?php $vi = max(1, $vpage - 2); $vf = min($vpages, $vi + 4); $vbase = ['tab' => 'marketing', 'filtro_status_voucher' => $filtro_status_voucher]; ?>
                    <?php if ($vpage > 1): ?><a href="?<?= http_build_query($vbase + ['vou_page' => $vpage - 1]) ?>"><i class="fa fa-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = $vi; $i <= $vf; $i++): ?><a href="?<?= http_build_query($vbase + ['vou_page' => $i]) ?>" class="<?= $i === $vpage ? 'current' : '' ?>"><?= $i ?></a><?php endfor; ?>
                    <?php if ($vpage < $vpages): ?><a href="?<?= http_build_query($vbase + ['vou_page' => $vpage + 1]) ?>"><i class="fa fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Overlay de progresso de envio -->
<div class="mkt-progress-overlay" id="mkt-progress">
    <div class="mkt-progress-box">
        <h3 id="mkt-prog-title">Enviando campanha...</h3>
        <p>Não feche esta janela até concluir.</p>
        <div class="mkt-bar"><span id="mkt-prog-bar"></span></div>
        <div class="mkt-progress-stats"><b id="mkt-prog-done">0</b> de <b id="mkt-prog-total">0</b> · <span id="mkt-prog-fail">0</span> falha(s)</div>
    </div>
</div>

<!-- Modal IA (mantido) -->
<div id="modal-ia-newsletter" class="modal-overlay">
    <div class="modal-content" style="max-width:600px;">
        <button class="modal-close">&times;</button>
        <div class="modern-modal-header" style="padding:0 0 15px 0; border-bottom:2px solid #f1f5f9; margin-bottom:20px; background:transparent; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.3rem; color:#1e293b; display:flex; align-items:center; gap:10px;"><i class="fa fa-magic" style="color:#a855f7;"></i> Assistente IA (Gerar Texto)</h3>
            <a href="admin.php?tab=configuracoes&subtab=gemini" class="btn-inline-add" style="background:#f8fafc; color:#475569; border:1px solid #cbd5e1; box-shadow:none; font-size:0.8rem; padding:6px 12px;"><i class="fa fa-key"></i> Editar Chave API</a>
        </div>
        <?php if (!$iaConfigurada): ?>
            <div class="alert-box danger" style="margin-bottom:20px; flex-direction:column; align-items:flex-start; gap:15px;">
                <div style="display:flex; align-items:center; gap:10px;"><i class="fa fa-exclamation-triangle" style="font-size:1.5rem;"></i><span>Configure sua chave da Groq nas Configurações para usar a IA.</span></div>
                <a href="admin.php?tab=configuracoes&subtab=gemini" class="btn-modern-submit" style="background:#ef4444; text-decoration:none; width:auto;"><i class="fa fa-cog"></i> Configurar IA Agora</a>
            </div>
        <?php else: ?>
            <p style="color:#64748b; font-size:0.95rem; margin:0 0 20px;">Diga o assunto e a IA escreve um e-mail persuasivo em HTML.</p>
            <form id="form-ia-gerar" onsubmit="gerarTextoIA(event)">
                <div class="modern-form">
                    <div class="form-group" style="margin-bottom:15px;"><label>Sobre o que é o e-mail?</label><textarea id="ia_topico" class="modern-textarea" rows="3" placeholder="Ex: novo serviço de barboterapia com 10% de desconto nesta semana..." required></textarea></div>
                    <div class="form-group" style="margin-bottom:20px;">
                        <label>Tom da Mensagem</label>
                        <select id="ia_tom" class="modern-select">
                            <option value="profissional e educado">Profissional e Educado</option>
                            <option value="descontraido, jovem e animado">Descontraído e Jovem</option>
                            <option value="persuasivo e focado em vendas (copywriting)">Persuasivo (Vendas)</option>
                            <option value="curto, direto e objetivo">Curto e Direto</option>
                        </select>
                    </div>
                    <button type="submit" id="btn_gerar_ia" class="btn-modern-submit" style="background:#a855f7;"><i class="fa fa-bolt"></i> Gerar Texto HTML</button>
                    <div id="ia_loading" style="display:none; text-align:center; color:#a855f7; margin-top:15px; font-weight:600;"><i class="fa fa-spinner fa-spin"></i> A IA está escrevendo...</div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
const MKT_CSRF = '<?= $csrf_token ?>';

function mktInserirTag(tag) {
    const ta = document.getElementById('nl_corpo');
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + tag + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + tag.length;
    ta.focus();
}

// --- Segmentação: mostra parâmetros e atualiza contador ---
function mktSegParams() {
    const seg = document.getElementById('nl_segmento').value;
    document.getElementById('nl_param_dias_wrap').style.display = (seg === 'sem_retorno') ? '' : 'none';
    document.getElementById('nl_param_barbeiro_wrap').style.display = (seg === 'barbeiro') ? '' : 'none';
}
function mktSegData() {
    return {
        segmento: document.getElementById('nl_segmento').value,
        dias: document.getElementById('nl_dias').value,
        barbeiro_id: (document.getElementById('nl_barbeiro') || {}).value || ''
    };
}
function mktAtualizarContador() {
    const el = document.getElementById('nl_count');
    el.textContent = '...';
    const fd = new FormData();
    fd.append('action', 'campanha_contar'); fd.append('csrf_token', MKT_CSRF);
    const d = mktSegData(); for (const k in d) fd.append(k, d[k]);
    fetch('admin_actions.php', { method: 'POST', body: fd }).then(r => r.json())
        .then(r => { el.textContent = r.success ? r.total : '—'; })
        .catch(() => { el.textContent = '—'; });
}
['nl_segmento','nl_dias','nl_barbeiro'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function () { mktSegParams(); mktAtualizarContador(); });
});
mktSegParams(); mktAtualizarContador();

// --- Preview ---
function mktPreview() {
    const assunto = document.getElementById('nl_assunto').value;
    const corpo = document.getElementById('nl_corpo').value;
    if (!corpo.trim()) { Swal.fire('Atenção', 'Escreva a mensagem primeiro.', 'warning'); return; }
    const fd = new FormData();
    fd.append('action', 'campanha_preview'); fd.append('csrf_token', MKT_CSRF);
    fd.append('assunto', assunto); fd.append('corpo_email', corpo);
    fetch('admin_actions.php', { method: 'POST', body: fd }).then(r => r.json()).then(r => {
        if (!r.success) { Swal.fire('Erro', r.error || 'Falha ao gerar preview.', 'error'); return; }
        Swal.fire({
            title: 'Pré-visualização', width: 680,
            html: '<iframe style="width:100%;height:60vh;border:1px solid #e2e8f0;border-radius:10px;background:#fff" srcdoc="' + r.html.replace(/"/g, '&quot;') + '"></iframe>',
            confirmButtonText: 'Fechar'
        });
    });
}

// --- Enviar teste ---
function mktTeste() {
    const assunto = document.getElementById('nl_assunto').value;
    const corpo = document.getElementById('nl_corpo').value;
    if (!assunto.trim() || !corpo.trim()) { Swal.fire('Atenção', 'Preencha assunto e mensagem.', 'warning'); return; }
    Swal.fire({
        title: 'Enviar e-mail de teste', input: 'email', inputValue: '<?= htmlspecialchars($_SESSION['email_admin'] ?? '') ?>',
        inputPlaceholder: 'seu-email@exemplo.com', showCancelButton: true, confirmButtonText: 'Enviar teste'
    }).then(res => {
        if (!res.isConfirmed || !res.value) return;
        const fd = new FormData();
        fd.append('action', 'campanha_teste'); fd.append('csrf_token', MKT_CSRF);
        fd.append('assunto', assunto); fd.append('corpo_email', corpo); fd.append('email_teste', res.value);
        Swal.fire({ title: 'Enviando...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        fetch('admin_actions.php', { method: 'POST', body: fd }).then(r => r.json()).then(r => {
            if (r.success) Swal.fire('Enviado!', 'E-mail de teste enviado para ' + res.value, 'success');
            else Swal.fire('Erro', r.error || 'Falha ao enviar.', 'error');
        });
    });
}

// --- Motor genérico de campanha em lote ---
function mktProgress(show) { document.getElementById('mkt-progress').classList.toggle('is-open', show); }
function mktSetProgress(done, total, fail) {
    document.getElementById('mkt-prog-done').textContent = done;
    document.getElementById('mkt-prog-total').textContent = total;
    document.getElementById('mkt-prog-fail').textContent = fail;
    document.getElementById('mkt-prog-bar').style.width = (total > 0 ? Math.round(done / total * 100) : 100) + '%';
}
async function mktRodarCampanha(startAction, startData, titulo) {
    const fd = new FormData();
    fd.append('action', startAction); fd.append('csrf_token', MKT_CSRF);
    for (const k in startData) fd.append(k, startData[k]);
    let r;
    try { r = await fetch('admin_actions.php', { method: 'POST', body: fd }).then(x => x.json()); }
    catch (e) { Swal.fire('Erro', 'Falha de comunicação.', 'error'); return; }
    if (!r.success) { Swal.fire('Erro', r.error || 'Não foi possível iniciar.', 'error'); return; }

    const id = r.campanha_id, total = r.total;
    document.getElementById('mkt-prog-title').textContent = titulo;
    mktSetProgress(0, total, 0); mktProgress(true);

    let done = false, enviados = 0, falhas = 0;
    while (!done) {
        const fl = new FormData();
        fl.append('action', 'campanha_lote'); fl.append('csrf_token', MKT_CSRF); fl.append('campanha_id', id);
        let lr;
        try { lr = await fetch('admin_actions.php', { method: 'POST', body: fl }).then(x => x.json()); }
        catch (e) { mktProgress(false); Swal.fire('Erro', 'Falha durante o envio. Parte pode ter sido enviada.', 'error'); return; }
        if (!lr.success) { mktProgress(false); Swal.fire('Erro', lr.error || 'Falha no lote.', 'error'); return; }
        enviados = lr.enviados; falhas = lr.falhas; done = lr.done;
        mktSetProgress(enviados + falhas, total, falhas);
    }
    mktProgress(false);
    await Swal.fire('Concluído!', enviados + ' e-mail(s) enviados' + (falhas ? (' · ' + falhas + ' falha(s)') : '') + '.', 'success');
    window.location = 'admin.php?tab=marketing';
}

function mktEnviarNewsletter() {
    const assunto = document.getElementById('nl_assunto').value.trim();
    const corpo = document.getElementById('nl_corpo').value.trim();
    if (!assunto || !corpo) { Swal.fire('Atenção', 'Preencha assunto e mensagem.', 'warning'); return; }
    const total = document.getElementById('nl_count').textContent;
    Swal.fire({
        title: 'Enviar campanha?', text: 'Será enviada para ' + total + ' destinatário(s).',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Sim, enviar', cancelButtonText: 'Cancelar'
    }).then(res => {
        if (!res.isConfirmed) return;
        mktRodarCampanha('campanha_iniciar', Object.assign({ assunto, corpo_email: corpo }, mktSegData()), 'Enviando campanha...');
    });
}

function mktEnviarReativacao(dias) {
    const sel = document.getElementById('wb_cupom');
    if (!sel || !sel.value) { Swal.fire('Atenção', 'Selecione um cupom.', 'warning'); return; }
    Swal.fire({
        title: 'Enviar reativação?', text: 'Cupom por e-mail aos clientes ausentes (exceto contatados nos últimos 15 dias).',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Sim, enviar', cancelButtonText: 'Cancelar'
    }).then(res => {
        if (!res.isConfirmed) return;
        mktRodarCampanha('campanha_reativacao_iniciar', { cupom_id: sel.value, dias_inatividade: dias }, 'Enviando reativação...');
    });
}

// --- IA gerar texto (mantido) ---
function gerarTextoIA(event) {
    event.preventDefault();
    const topico = document.getElementById('ia_topico').value;
    const tom = document.getElementById('ia_tom').value;
    const btn = document.getElementById('btn_gerar_ia');
    const loading = document.getElementById('ia_loading');
    btn.disabled = true; loading.style.display = 'block';
    const fd = new FormData();
    fd.append('action', 'gerar_texto_ia'); fd.append('topico', topico); fd.append('tom', tom); fd.append('csrf_token', MKT_CSRF);
    fetch('admin_actions.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('nl_corpo').value = data.texto;
            document.querySelector('#modal-ia-newsletter .modal-close').click();
            document.getElementById('nl_corpo').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else { Swal.fire('Erro', 'Erro ao gerar texto: ' + data.error, 'error'); }
    }).catch(() => Swal.fire('Erro', 'Erro de comunicação com o servidor.', 'error'))
      .finally(() => { if (btn) btn.disabled = false; if (loading) loading.style.display = 'none'; });
}

// --- Win-back IA por cliente (WhatsApp) ---
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.risco-ia');
    if (!btn) return;
    const nome = btn.dataset.nome || 'Cliente';
    const tel = btn.dataset.tel || '';
    const dados = 'Nome: ' + nome + '\nÚltima visita: ' + (btn.dataset.ultima || '?') + '\nDias sem retornar: ' + (btn.dataset.dias || '?');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    fetch('ajax_gemini.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action: 'reativacao_cliente_ia', dados_cliente: dados })
    }).then(r => r.json()).then(data => {
        if (!data || !data.success) throw new Error((data && data.error) || 'Falha na IA');
        const msg = data.resposta || '';
        Swal.fire({
            title: 'Mensagem para ' + nome, input: 'textarea', inputValue: msg,
            inputAttributes: { style: 'height:160px;' }, showCancelButton: true,
            confirmButtonText: tel ? '<i class="fab fa-whatsapp"></i> Enviar no WhatsApp' : 'Copiar',
            confirmButtonColor: '#25D366', cancelButtonText: 'Fechar'
        }).then(res => {
            if (res.isConfirmed && res.value) {
                if (tel) window.open('https://wa.me/55' + tel + '?text=' + encodeURIComponent(res.value), '_blank');
                else navigator.clipboard && navigator.clipboard.writeText(res.value);
            }
        });
    }).catch(err => Swal.fire({ icon: 'error', title: 'Erro', text: err.message }))
      .finally(() => { btn.disabled = false; btn.innerHTML = original; });
});
</script>

<?php
// admin_tabs/fidelidade.php
// Contém o HTML da aba "Fidelidade".
// Variáveis como $configFidelidade e $clientesFidelidadePaginados vêm do admin_data.php (SQLite)

$csrf_token = generate_csrf_token(); // GERANDO O TOKEN DE SEGURANÇA PARA A ABA
$pontos_meta = $configFidelidade['pontos_necessarios'] ?? 10;
?>

<style>
    /* ==========================================================================
       ESTILOS PREMIUM PARA PROGRAMA DE FIDELIDADE (SAAS GOLD EDITION)
       ========================================================================== */
    .fidelidade-layout {
        display: flex;
        flex-direction: column;
        gap: 30px;
        animation: fadeIn 0.5s ease-out;
        font-family: 'Inter', sans-serif;
    }

    /* Componentes compartilhados (mensagens, cabeçalho, widgets, tabela,
       paginação, campos) vivem em css/admin_components.css.
       Aqui ficam só os elementos próprios do programa de fidelidade --
       o dourado é mantido apenas onde representa pontos/medalha. */

    /* --- Grid de Configuração Premium --- */
    .premium-config-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }
    .config-block {
        background: #f8fafc; border-radius: 16px; padding: 20px; border: 1px solid #e2e8f0;
        display: flex; flex-direction: column; gap: 10px; transition: 0.2s;
    }
    .config-block:focus-within { background: #fff; border-color: var(--secondary-color, #007bff); box-shadow: 0 4px 15px color-mix(in srgb, var(--secondary-color, #007bff) 12%, transparent); }

    .config-block label { font-weight: 700; color: #475569; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
    .config-block label i { color: #94a3b8; font-size: 1.1rem; }

    .config-block .modern-input, .config-block .modern-select { background: #fff; font-size: 1.1rem; font-weight: 700; }

    .btn-gold-submit {
        background: var(--secondary-color, #007bff); color: white; border: none; padding: 14px 30px;
        border-radius: 12px; font-weight: 800; font-size: 1.05rem; cursor: pointer; transition: 0.3s;
        display: inline-flex; align-items: center; justify-content: center; gap: 10px;
        box-shadow: 0 4px 15px color-mix(in srgb, var(--secondary-color, #007bff) 30%, transparent);
        align-self: flex-start; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .btn-gold-submit:hover { transform: translateY(-3px); filter: brightness(1.1); }

    .config-section-title { font-weight: 800; color: #334155; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 5px 0 12px; display: flex; align-items: center; gap: 8px; }
    .config-section-title i { color: #f59e0b; }
    .config-block small { font-size: 0.78rem; font-weight: 500; text-transform: none; letter-spacing: 0; }

    /* Botões rápidos +/- na tabela */
    .btn-step { background: #fff; border: 1px solid #cbd5e1; color: #334155; width: 34px; height: 34px; border-radius: 8px; font-weight: 800; font-size: 1rem; cursor: pointer; transition: 0.15s; }
    .btn-step:hover { background: #0f172a; color: #fff; border-color: #0f172a; }
    .btn-step.minus:hover { background: #e11d48; border-color: #e11d48; }
    .btn-step.plus:hover { background: #10b981; border-color: #10b981; }
    .btn-extrato { background: transparent; border: none; color: #2563eb; font-weight: 700; cursor: pointer; font-size: 0.85rem; margin-left: 8px; }
    .btn-extrato:hover { text-decoration: underline; }

    /* Modal de extrato */
    .fid-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.55); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 20px; }
    .fid-modal-overlay.open { display: flex; }
    .fid-modal { background: #fff; border-radius: 18px; max-width: 480px; width: 100%; max-height: 80vh; overflow: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
    .fid-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: #fff; }
    .fid-modal-head h4 { margin: 0; font-size: 1.15rem; color: #0f172a; }
    .fid-modal-close { background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 1rem; color: #475569; }
    .fid-modal-close:hover { background: #e2e8f0; }
    .fid-modal-body { padding: 16px 24px 24px; }
    .fid-hist-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed #e2e8f0; font-size: 0.92rem; }
    .fid-hist-item:last-child { border-bottom: none; }
    .fid-hist-pts { font-weight: 800; }
    .fid-hist-pts.pos { color: #10b981; }
    .fid-hist-pts.neg { color: #e11d48; }
    .fid-hist-empty { text-align: center; color: #94a3b8; padding: 30px; }
    .fid-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

    /* --- Barra de Busca Interna --- */
    .search-wrapper { position: relative; display: flex; align-items: center; width: 100%; max-width: 400px; }
    .search-wrapper i { position: absolute; left: 18px; color: #94a3b8; font-size: 1.1rem; }
    .search-input {
        padding: 12px 15px 12px 45px; border-radius: 12px 0 0 12px; border: 1px solid #cbd5e1; border-right: none;
        background: #f8fafc; color: #334155; font-family: 'Inter', sans-serif; font-size: 0.95rem; font-weight: 500;
        outline: none; transition: 0.2s; width: 100%;
    }
    .search-input:focus { background: #ffffff; border-color: var(--secondary-color, #007bff); }
    .search-btn {
        background: #0f172a; color: white; border: none; padding: 12px 25px;
        border-radius: 0 12px 12px 0; font-weight: 700; cursor: pointer; transition: 0.2s; font-size: 0.95rem;
    }
    .search-btn:hover { background: #1e293b; }

    /* Estilos do Cliente na Tabela */
    .client-cell { display: flex; align-items: center; gap: 15px; }
    .avatar-initials {
        width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, #fef3c7, #fde68a);
        color: #b45309; display: flex; align-items: center; justify-content: center;
        font-weight: 900; font-size: 1.2rem; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .client-name { color: #0f172a; font-size: 1.1rem; margin: 0; font-weight: 800; }

    /* Badge e Progresso */
    .points-container { display: flex; flex-direction: column; gap: 8px; max-width: 200px; }
    .points-header { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; font-weight: 700; color: #64748b; }
    .points-header strong { font-size: 1.1rem; color: #0f172a; }
    
    .progress-track { width: 100%; height: 8px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 10px; transition: width 0.5s ease; background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .progress-fill.ready { background: linear-gradient(90deg, #10b981, #34d399); } /* Verde quando atinge a meta */

    /* Formulário Inline na Tabela */
    .inline-adjust-form { display: flex; align-items: center; gap: 8px; margin: 0; background: #f8fafc; padding: 6px; border-radius: 12px; border: 1px solid #e2e8f0; width: fit-content; float: right; }
    .input-adjust {
        width: 70px; padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1;
        font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700; color: #1e293b; background: #fff; text-align: center;
        outline: none; transition: 0.2s;
    }
    .input-adjust:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
    .btn-inline-save {
        background: #0f172a; color: white; border: none; padding: 9px 15px; border-radius: 8px;
        font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px;
    }
    .btn-inline-save:hover { background: #10b981; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(16,185,129,0.2); }

    /* Responsividade */
    @media (max-width: 768px) {
        .btn-gold-submit { width: 100%; }
        .search-wrapper { max-width: 100%; }
        .inline-adjust-form { float: none; width: 100%; justify-content: space-between; }
        .points-container { max-width: 100%; }
    }
</style>

<div class="fidelidade-layout">

    <div class="dash-header-bar">
        <div class="dash-title">
            <h3><i class="fa fa-crown"></i> Clube de Vantagens</h3>
            <p>Configure as regras de pontuação e fidelize seus clientes com recompensas exclusivas.</p>
        </div>
    </div>

    <?php
    // As mensagens de sucesso/erro são exibidas pelo banner global do admin.php.
    $fidComPontos = 0; $fidTotalPontos = 0; $fidAptos = 0; $fidTopPontos = 0;
    foreach (($pontosFidelidade ?? []) as $pf) {
        $pf = (int)$pf;
        if ($pf > 0) $fidComPontos++;
        $fidTotalPontos += max(0, $pf);
        if ($pf >= $pontos_meta) $fidAptos++;
        if ($pf > $fidTopPontos) $fidTopPontos = $pf;
    }
    $fidAtivo = (($configFidelidade['ativado'] ?? 0) == 1);

    // Resgates no mês corrente (extrato de fidelidade com pontos negativos = resgate)
    $fidResgatesMes = 0;
    try {
        $pdoFid = getDB();
        $stmtR = $pdoFid->prepare("SELECT COUNT(*) FROM fidelidade_historico WHERE pontos < 0 AND LOWER(descricao) LIKE '%resgate%' AND substr(timestamp,1,7) = ?");
        $stmtR->execute([date('Y-m')]);
        $fidResgatesMes = (int)$stmtR->fetchColumn();
    } catch (Exception $e) { $fidResgatesMes = 0; }
    ?>
    <div class="kpi-grid" style="margin-bottom: 20px;" aria-label="Resumo do programa de fidelidade">
        <div class="kpi-card">
            <div class="kpi-icon" style="background:<?= $fidAtivo ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $fidAtivo ? '#059669' : '#dc2626' ?>;"><i class="fa fa-<?= $fidAtivo ? 'circle-check' : 'circle-pause' ?>"></i></div>
            <div class="kpi-info"><h4>Status do programa</h4><div class="value" style="font-size:1.2rem; color:<?= $fidAtivo ? '#059669' : '#dc2626' ?>;"><?= $fidAtivo ? 'Ativo' : 'Pausado' ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa fa-users"></i></div>
            <div class="kpi-info"><h4>Clientes com pontos</h4><div class="value"><?= (int)$fidComPontos ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fffbeb; color:#d97706;"><i class="fa fa-coins"></i></div>
            <div class="kpi-info"><h4>Pontos em circulação</h4><div class="value"><?= (int)$fidTotalPontos ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f5f3ff; color:#7c3aed;"><i class="fa fa-gift"></i></div>
            <div class="kpi-info"><h4>Aptos a resgatar</h4><div class="value"><?= (int)$fidAptos ?> <span style="font-size:.9rem; color:#94a3b8;">(≥ <?= (int)$pontos_meta ?> pts)</span></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fef2f2; color:#e11d48;"><i class="fa fa-ticket-alt"></i></div>
            <div class="kpi-info"><h4>Resgates no mês</h4><div class="value"><?= (int)$fidResgatesMes ?></div></div>
        </div>
    </div>

    <div class="widget-card">
        <h4 class="widget-header"><i class="fa fa-sliders-h"></i> Regras de Premiação</h4>
        
        <form method="POST">
            <input type="hidden" name="action" value="salvar_config_fidelidade">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <?php
                $cfgModoGanho = $configFidelidade['modo_ganho'] ?? 'visita';
                $cfgTipoRecomp = $configFidelidade['tipo_recompensa'] ?? 'percentual';
                $cfgBase = $configFidelidade['base_desconto'] ?? 'mais_barato';
            ?>

            <div class="config-section-title"><i class="fa fa-coins"></i> Como o cliente ganha pontos</div>
            <div class="premium-config-grid">
                <div class="config-block">
                    <label><i class="fa fa-toggle-on"></i> Status do Programa</label>
                    <select name="fidelidade_ativado" class="modern-select">
                        <option value="1" <?= ($configFidelidade['ativado'] ?? 0) == 1 ? 'selected' : '' ?>>🟢 Programa Ativo</option>
                        <option value="0" <?= ($configFidelidade['ativado'] ?? 0) == 0 ? 'selected' : '' ?>>🔴 Pausado</option>
                    </select>
                </div>

                <div class="config-block">
                    <label><i class="fa fa-arrow-trend-up"></i> Modo de Pontuação</label>
                    <select name="modo_ganho" class="modern-select" id="fid-modo-ganho">
                        <option value="visita" <?= $cfgModoGanho === 'visita' ? 'selected' : '' ?>>Pontos fixos por visita</option>
                        <option value="valor" <?= $cfgModoGanho === 'valor' ? 'selected' : '' ?>>Por valor gasto (R$)</option>
                    </select>
                </div>

                <div class="config-block" data-modo="visita">
                    <label><i class="fa fa-star"></i> Pontos por Visita</label>
                    <input type="number" name="pontos_por_visita" class="modern-input" min="1" value="<?= (int)($configFidelidade['pontos_por_visita'] ?? 1) ?>">
                    <small style="color:#94a3b8;">Pontos ganhos a cada atendimento concluído.</small>
                </div>

                <div class="config-block" data-modo="valor">
                    <label><i class="fa fa-money-bill-wave"></i> R$ por 1 Ponto</label>
                    <input type="number" step="0.01" min="0" name="real_por_ponto" class="modern-input" value="<?= htmlspecialchars($configFidelidade['real_por_ponto'] ?? 0) ?>">
                    <small style="color:#94a3b8;">Ex.: 50 = 1 ponto a cada R$ 50 gastos.</small>
                </div>
            </div>

            <div class="config-section-title"><i class="fa fa-gift"></i> Qual é a recompensa</div>
            <div class="premium-config-grid">
                <div class="config-block">
                    <label><i class="fa fa-flag-checkered"></i> Pontos para Resgate</label>
                    <input type="number" name="pontos_necessarios" class="modern-input" min="1" value="<?= htmlspecialchars($pontos_meta) ?>" required>
                </div>

                <div class="config-block">
                    <label><i class="fa fa-award"></i> Tipo de Recompensa</label>
                    <select name="tipo_recompensa" class="modern-select" id="fid-tipo-recomp">
                        <option value="percentual" <?= $cfgTipoRecomp === 'percentual' ? 'selected' : '' ?>>Desconto percentual (%)</option>
                        <option value="valor_fixo" <?= $cfgTipoRecomp === 'valor_fixo' ? 'selected' : '' ?>>Desconto fixo (R$)</option>
                        <option value="servico_gratis" <?= $cfgTipoRecomp === 'servico_gratis' ? 'selected' : '' ?>>Serviço grátis (o mais caro)</option>
                    </select>
                </div>

                <div class="config-block" data-recomp="percentual">
                    <label><i class="fa fa-percentage"></i> Desconto Oferecido (%)</label>
                    <input type="number" name="desconto_percentual" class="modern-input" min="0" value="<?= $configFidelidade['desconto_percentual'] ?? 50 ?>">
                </div>

                <div class="config-block" data-recomp="percentual">
                    <label><i class="fa fa-crosshairs"></i> Incide sobre</label>
                    <select name="base_desconto" class="modern-select">
                        <option value="mais_barato" <?= $cfgBase === 'mais_barato' ? 'selected' : '' ?>>Serviço mais barato</option>
                        <option value="mais_caro" <?= $cfgBase === 'mais_caro' ? 'selected' : '' ?>>Serviço mais caro</option>
                        <option value="total" <?= $cfgBase === 'total' ? 'selected' : '' ?>>Total do atendimento</option>
                    </select>
                </div>

                <div class="config-block" data-recomp="valor_fixo">
                    <label><i class="fa fa-money-bill"></i> Valor do Desconto (R$)</label>
                    <input type="number" step="0.01" min="0" name="valor_desconto_fixo" class="modern-input" value="<?= htmlspecialchars($configFidelidade['valor_desconto_fixo'] ?? 0) ?>">
                </div>
            </div>

            <button type="submit" class="btn-gold-submit"><i class="fa fa-save"></i> Atualizar Regras do Clube</button>
        </form>
    </div>

    <div class="widget-card">
        
        <?php $apenasAptos = (($_GET['fid_aptos'] ?? '') === '1'); ?>
        <div class="widget-header widget-header-flex" style="border-bottom: none; padding-bottom: 0;">
            <h4 style="margin: 0; display: flex; align-items: center; gap: 10px;"><i class="fa fa-users"></i> Ranking de Pontos</h4>

            <form method="GET" style="margin: 0; width: 100%; max-width: 400px;">
                <input type="hidden" name="tab" value="fidelidade">
                <?php if ($apenasAptos): ?><input type="hidden" name="fid_aptos" value="1"><?php endif; ?>
                <div class="search-wrapper">
                    <i class="fa fa-search"></i>
                    <input type="text" name="busca_fidelidade" class="search-input" placeholder="Buscar cliente..." value="<?= htmlspecialchars($busca_fidelidade) ?>">
                    <button type="submit" class="search-btn">Procurar</button>
                </div>
            </form>
        </div>

        <div class="fid-toolbar" style="margin: 15px 0 5px;">
            <a href="?tab=fidelidade<?= $apenasAptos ? '' : '&fid_aptos=1' ?><?= $busca_fidelidade !== '' ? '&busca_fidelidade=' . urlencode($busca_fidelidade) : '' ?>"
               class="btn-inline-save" style="<?= $apenasAptos ? 'background:#10b981;' : 'background:#0f172a;' ?> text-decoration:none;">
                <i class="fa fa-filter"></i> <?= $apenasAptos ? 'Mostrando só aptos ✓' : 'Só aptos a resgatar' ?>
            </a>
            <a href="admin.php?action=exportar_fidelidade&csrf_token=<?= urlencode($csrf_token) ?>"
               class="btn-inline-save" style="background:#0ea5e9; text-decoration:none;">
                <i class="fa fa-file-csv"></i> Exportar CSV
            </a>
        </div>

        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Cliente Membro</th>
                        <th>Progresso da Recompensa</th>
                        <th style="text-align: right;">Gerenciar Pontos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($clientesFidelidadePaginados)): ?>
                        <tr><td colspan="3" style="text-align:center; padding: 50px; color: #94a3b8; font-size: 1.1rem;"><i class="fa fa-search" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>Nenhum cliente encontrado na busca.</td></tr>
                    <?php else: $fidHistStore = []; ?>
                        <?php foreach($clientesFidelidadePaginados as $id => $cliente):
                            $nome = htmlspecialchars($cliente['nome']);
                            $inicial = strtoupper(substr($nome, 0, 1));
                            $pontos_atuais = (int)($pontosFidelidade[$id] ?? 0);
                            $fidHistStore[$id] = ['nome' => $cliente['nome'], 'hist' => (function_exists('getFidelityHistory') ? getFidelityHistory($id) : [])];
                            
                            // Calcula progresso limitando a 100%
                            $progresso_calc = ($pontos_meta > 0) ? ($pontos_atuais / $pontos_meta) * 100 : 0;
                            $porcentagem = min(100, max(0, $progresso_calc));
                            
                            $is_ready = ($pontos_atuais >= $pontos_meta);
                        ?>
                        <tr>
                            <td data-label="Cliente">
                                <div class="client-cell">
                                    <div class="avatar-initials"><?= $inicial ?></div>
                                    <h4 class="client-name"><?= $nome ?></h4>
                                </div>
                            </td>
                            
                            <td data-label="Progresso">
                                <div class="points-container">
                                    <div class="points-header">
                                        <span><i class="fa fa-star" style="color:#f59e0b;"></i> <strong><?= $pontos_atuais ?></strong> / <?= $pontos_meta ?> pts</span>
                                        <?php if($is_ready): ?>
                                            <span style="color: #10b981;"><i class="fa fa-gift"></i> Prêmio Liberado!</span>
                                        <?php else: ?>
                                            <span><?= number_format($porcentagem, 0) ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-fill <?= $is_ready ? 'ready' : '' ?>" style="width: <?= $porcentagem ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                            
                            <td data-label="Ajuste Manual">
                                <div style="display:flex; align-items:center; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                                    <!-- Botões rápidos +/- (ajuste por delta) -->
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="ajustar_pontos">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="cliente_id" value="<?= $id ?>">
                                        <input type="hidden" name="delta" value="-1">
                                        <button type="submit" class="btn-step minus" title="Remover 1 ponto" <?= $pontos_atuais <= 0 ? 'disabled style="opacity:.4;cursor:not-allowed;"' : '' ?>>−</button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="ajustar_pontos">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="cliente_id" value="<?= $id ?>">
                                        <input type="hidden" name="delta" value="1">
                                        <button type="submit" class="btn-step plus" title="Adicionar 1 ponto">+</button>
                                    </form>

                                    <!-- Definir saldo exato (com confirmação) -->
                                    <form method="POST" class="inline-adjust-form" onsubmit="return confirm('Definir o saldo deste cliente para o valor informado? Isso substitui o total atual.');">
                                        <input type="hidden" name="action" value="ajustar_pontos">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="cliente_id" value="<?= $id ?>">
                                        <input type="number" name="novos_pontos" class="input-adjust" value="<?= $pontos_atuais ?>" min="0" title="Definir saldo exato">
                                        <button type="submit" class="btn-inline-save" title="Definir Novo Saldo">
                                            <i class="fa fa-check"></i> Definir
                                        </button>
                                    </form>

                                    <button type="button" class="btn-extrato" data-fid-extrato="<?= htmlspecialchars($id) ?>" title="Ver extrato de pontos">
                                        <i class="fa fa-list"></i> Extrato
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($fidelidade_totalPages > 1): ?>
        <div class="modern-pagination">
            <?php for ($i = 1; $i <= $fidelidade_totalPages; $i++): ?>
                <a href="?tab=fidelidade&fid_page=<?= $i ?>&busca_fidelidade=<?= urlencode($busca_fidelidade) ?><?= $apenasAptos ? '&fid_aptos=1' : '' ?>" class="<?= ($i == $fidelidade_currentPage ? 'current' : '') ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Store oculto com o extrato de cada cliente da página (usado pelo modal) -->
    <div id="fid-hist-store" style="display:none;">
        <?php foreach (($fidHistStore ?? []) as $cid => $info): ?>
            <div class="fid-hist-tpl" data-id="<?= htmlspecialchars($cid) ?>" data-nome="<?= htmlspecialchars($info['nome']) ?>">
                <?php if (empty($info['hist'])): ?>
                    <div class="fid-hist-empty">Nenhum lançamento de pontos ainda.</div>
                <?php else: ?>
                    <?php foreach ($info['hist'] as $h):
                        $pts = (int)$h['pontos'];
                        $sinal = $pts > 0 ? '+' : '';
                    ?>
                        <div class="fid-hist-item">
                            <div>
                                <div style="font-weight:600; color:#334155;"><?= htmlspecialchars($h['descricao']) ?></div>
                                <div style="font-size:0.78rem; color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($h['timestamp'])) ?></div>
                            </div>
                            <div class="fid-hist-pts <?= $pts >= 0 ? 'pos' : 'neg' ?>"><?= $sinal . $pts ?> pts</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Modal de extrato -->
    <div class="fid-modal-overlay" id="fid-extrato-modal">
        <div class="fid-modal">
            <div class="fid-modal-head">
                <h4><i class="fa fa-list" style="color:#f59e0b;"></i> Extrato — <span id="fid-extrato-nome"></span></h4>
                <button type="button" class="fid-modal-close" id="fid-extrato-close">&times;</button>
            </div>
            <div class="fid-modal-body" id="fid-extrato-body"></div>
        </div>
    </div>

</div>

<script>
(function () {
    // Alterna campos do formulário de regras conforme modo de ganho / tipo de recompensa
    var modo = document.getElementById('fid-modo-ganho');
    var tipo = document.getElementById('fid-tipo-recomp');

    function toggle(attr, valor) {
        document.querySelectorAll('[' + attr + ']').forEach(function (el) {
            el.style.display = (el.getAttribute(attr) === valor) ? '' : 'none';
        });
    }
    function syncModo() { if (modo) toggle('data-modo', modo.value); }
    function syncTipo() { if (tipo) toggle('data-recomp', tipo.value); }

    if (modo) { modo.addEventListener('change', syncModo); syncModo(); }
    if (tipo) { tipo.addEventListener('change', syncTipo); syncTipo(); }

    // Modal de extrato
    var overlay = document.getElementById('fid-extrato-modal');
    var body = document.getElementById('fid-extrato-body');
    var nomeEl = document.getElementById('fid-extrato-nome');
    var closeBtn = document.getElementById('fid-extrato-close');

    document.querySelectorAll('[data-fid-extrato]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-fid-extrato');
            var tpl = document.querySelector('.fid-hist-tpl[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
            if (!tpl || !overlay) return;
            nomeEl.textContent = tpl.getAttribute('data-nome') || '';
            body.innerHTML = tpl.innerHTML;
            overlay.classList.add('open');
        });
    });

    function fecharExtrato() { if (overlay) overlay.classList.remove('open'); }
    if (closeBtn) closeBtn.addEventListener('click', fecharExtrato);
    // Aqui o clique fora fecha de propósito: é um modal só de leitura, sem risco de perder trabalho.
    if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) fecharExtrato(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharExtrato(); });
})();
</script>
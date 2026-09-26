<?php
// admin_tabs/barbeiros.php
// Contém o HTML da aba "Barbeiros".
// Variáveis como $barbeirosPaginados e $horariosTrabalhoRaw vêm do admin_data.php (Já carregados via SQLite)

$csrf_token = generate_csrf_token(); // GERANDO O TOKEN DE SEGURANÇA PARA A ABA
?>

<style>
    /* ==========================================================================
       ESTILOS PREMIUM PARA BARBEIROS (EQUIPE)
       ========================================================================== */
    .barbeiros-layout {
        display: flex;
        flex-direction: column;
        gap: 25px;
        animation: fadeIn 0.4s ease-out;
    }

    /* Componentes compartilhados (cabeçalho, cards, badges, botões de ação,
       paginação) vivem em css/admin_components.css */

    /* Header do card com fundo levemente destacado */
    .modern-card-header {
        display: flex; justify-content: space-between; align-items: flex-start; background: #fdfdfd;
    }

    /* --- Barra de busca/filtro da equipe --- */
    .barbeiro-toolbar { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(170px, 220px) auto auto; gap: 10px; align-items: center; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .barbeiro-search-control { position: relative; min-width: 0; }
    .barbeiro-search-control > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
    .barbeiro-toolbar .filter-input { width: 100%; box-sizing: border-box; height: 44px; padding: 0 14px 0 40px; border: 1px solid #d8e0ea; border-radius: 8px; outline: none; background: #fff; color: #27364b; font: 600 .88rem 'Inter', sans-serif; transition: border-color .2s, box-shadow .2s; }
    .barbeiro-toolbar .filter-input::placeholder { color: #94a3b8; font-weight: 500; }
    .barbeiro-toolbar .filter-input:focus { border-color: var(--secondary-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--secondary-color) 11%, transparent); }
    .barbeiro-toolbar .modern-select { height: 44px; padding: 0 14px; background: #fff; cursor: pointer; }
    .barbeiro-toolbar .btn-modern-filter { width: 44px; height: 44px; min-height: 44px; padding: 0; }
    .btn-clear-barbeiro { width: 44px; height: 44px; border: 1px solid #d8e0ea; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #64748b; background: #fff; text-decoration: none; transition: .2s; }
    .btn-clear-barbeiro:hover { color: #dc2626; border-color: #fecaca; background: #fff7f7; }
    .barbeiro-results-count { margin: 0; color: #64748b; font-size: .88rem; }
    .barbeiro-results-count strong { color: #1e293b; }

    .card-barbeiro-info { display: flex; align-items: center; gap: 15px; }
    .card-avatar { width: 60px; height: 60px; }
    .card-title { margin: 0; font-size: 1.15rem; color: #1e293b; font-weight: 800; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }

    /* Corpo: Estatísticas Rápidas (3 colunas em caixas, próprio desta aba) */
    .card-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .card-stat-item {
        background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #f1f5f9;
        display: flex; flex-direction: column; justify-content: center; text-align: center;
    }
    .card-stat-item i { color: #94a3b8; font-size: 1.1rem; margin-bottom: 5px; }
    .card-stat-label { font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
    .card-stat-value { font-size: 1.1rem; }

    .stat-receita i { color: #10b981; }
    .stat-concluidos i { color: var(--secondary-color, #007bff); }
    .stat-media i { color: #f59e0b; }

    /* Footer: Ações */
    .modern-card-footer { padding: 15px 20px; background: #fdfdfd; display: flex; justify-content: flex-end; gap: 8px; }
    .btn-info-action { background: #0ea5e9; }

    /* --- Seção de Configuração de Grade --- */
    .config-schedule-section {
        background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); padding: 30px; margin-top: 20px;
    }

    .config-schedule-header {
        border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;
    }
    .config-schedule-header h3 { margin: 0; color: #1e293b; font-size: 1.3rem; font-weight: 800; }
    .config-schedule-header i { color: var(--secondary-color, #007bff); }

    .form-group label { display: block; font-weight: 600; color: #475569; margin-bottom: 8px; font-size: 0.95rem; }
    
    .custom-select-wrapper { position: relative; max-width: 400px; margin-bottom: 25px; }
    .custom-select-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.1rem; pointer-events: none;}
    .custom-select-wrapper select {
        width: 100%; padding: 12px 15px 12px 45px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: 'Inter', sans-serif;
        font-size: 1rem; color: #1e293b; background: #f8fafc; outline: none; transition: 0.2s; cursor: pointer; appearance: none;
    }
    .custom-select-wrapper select:focus { border-color: var(--secondary-color); background: white; box-shadow: 0 0 0 3px rgba(0,123,255,0.1); }
    /* Adiciona a setinha personalizada do select */
    .custom-select-wrapper::after { content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; font-size: 0.9rem;}

    /* Tabela de Grade de Horários */
    .schedule-table { width: 100%; border-collapse: collapse; }
    .schedule-table th { background: #f8fafc; padding: 15px; font-weight: 700; color: #475569; text-transform: uppercase; font-size: 0.85rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .schedule-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .schedule-table tr:last-child td { border-bottom: none; }
    .schedule-table tr:hover { background: #fdfdfd; }

    .day-label { font-weight: 600; color: #1e293b; cursor: pointer; margin: 0; }
    
    .time-input {
        padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: monospace; font-size: 1rem;
        color: #334155; background: #fff; outline: none; transition: 0.2s; width: 100%; max-width: 150px;
    }
    .time-input:focus { border-color: var(--secondary-color); box-shadow: 0 0 0 3px rgba(0,123,255,0.1); }

    /* Custom Checkbox */
    .custom-checkbox { width: 22px; height: 22px; cursor: pointer; accent-color: var(--secondary-color, #007bff); }

    .btn-replicar {
        background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 10px 15px; border-radius: 8px;
        font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-replicar:hover { background: #e2e8f0; color: #1e293b; }

    .btn-save-grade {
        background: var(--secondary-color, #007bff); color: white; border: none; padding: 15px 30px; border-radius: 10px;
        font-weight: 700; font-size: 1.05rem; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(0,123,255,0.2);
        display: inline-flex; align-items: center; gap: 8px; margin-top: 20px; width: 100%; justify-content: center;
    }
    .btn-save-grade:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,123,255,0.3); }

    @media (max-width: 768px) {
        .barbeiro-toolbar { grid-template-columns: 1fr 1fr; }
        .barbeiro-search-control { grid-column: 1 / -1; }
        .barbeiro-toolbar .btn-modern-filter, .btn-clear-barbeiro { width: 100%; }

        .schedule-table thead { display: none; }
        .schedule-table, .schedule-table tbody, .schedule-table tr, .schedule-table td { display: block; width: 100%; box-sizing: border-box; }
        .schedule-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; }
        .schedule-table td { text-align: left; border-bottom: none; padding: 10px; display: flex; align-items: center; justify-content: space-between; }
        .schedule-table td:nth-child(1) { justify-content: flex-start; gap: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px; margin-bottom: 5px; }
        .schedule-table td:nth-child(2) { display: none; /* O label já vai no primeiro td para mobile */ }
        .schedule-table td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 0.85rem; text-transform: uppercase; width: 30%; }
        .time-input { max-width: 60%; }
    }
</style>

<div class="barbeiros-layout">

    <div class="dash-header-bar">
        <div class="dash-title">
            <h3><i class="fa fa-users"></i> Gerenciar Equipe</h3>
            <p>Acompanhe o desempenho, edite perfis e gerencie a grade de horários dos profissionais.</p>
        </div>
        <button class="btn-modern-add" data-modal-target="#modal-barbeiro"><i class="fa fa-user-plus"></i> Novo Profissional</button>
    </div>

    <?php
    $eqAtivos = 0; $eqConcluidos = 0; $eqReceita = 0; $eqSomaMedias = 0; $eqComAval = 0;
    $eqTop = ['nome' => '—', 'receita' => 0];
    foreach ($barbeirosArr as $bId => $bItem) {
        if (($bItem['status'] ?? 'ativo') !== 'inativo') $eqAtivos++;
        $st = $estatisticas_barbeiros[$bId] ?? ['concluidos' => 0, 'receita' => 0, 'media_avaliacoes' => 0];
        $eqConcluidos += (int)$st['concluidos'];
        $eqReceita += (float)$st['receita'];
        if ((float)$st['media_avaliacoes'] > 0) { $eqSomaMedias += (float)$st['media_avaliacoes']; $eqComAval++; }
        if ((float)$st['receita'] > $eqTop['receita']) $eqTop = ['nome' => $bItem['nome'], 'receita' => (float)$st['receita']];
    }
    $eqMediaGeral = $eqComAval > 0 ? round($eqSomaMedias / $eqComAval, 1) : 0;
    ?>
    <div class="kpi-grid" style="margin-bottom: 20px;" aria-label="Resumo da equipe">
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa fa-user-tie"></i></div>
            <div class="kpi-info"><h4>Profissionais ativos</h4><div class="value"><?= (int)$eqAtivos ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#ecfdf5; color:#059669;"><i class="fa fa-check-double"></i></div>
            <div class="kpi-info"><h4>Atendimentos concluídos</h4><div class="value"><?= (int)$eqConcluidos ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f0fdf4; color:#16a34a;"><i class="fa fa-sack-dollar"></i></div>
            <div class="kpi-info"><h4>Receita da equipe</h4><div class="value" style="font-size:1.4rem;">R$ <?= number_format($eqReceita, 2, ',', '.') ?></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fffbeb; color:#d97706;"><i class="fa fa-star"></i></div>
            <div class="kpi-info"><h4>Média geral</h4><div class="value"><?= number_format($eqMediaGeral, 1, ',', '.') ?> <span style="font-size:1rem; color:#94a3b8;">/ 5</span></div></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f5f3ff; color:#7c3aed;"><i class="fa fa-trophy"></i></div>
            <div class="kpi-info"><h4>Destaque (faturamento)</h4><div class="value" style="font-size:1.05rem;"><?= htmlspecialchars($eqTop['nome']) ?></div></div>
        </div>
    </div>

    <form method="GET" action="admin.php" class="barbeiro-toolbar">
        <input type="hidden" name="tab" value="barbeiros">
        <div class="barbeiro-search-control">
            <i class="fa fa-search"></i>
            <input type="search" name="busca_barbeiro" class="filter-input"
                   value="<?= htmlspecialchars($busca_barbeiro ?? '') ?>"
                   placeholder="Buscar por nome ou usuário de acesso...">
        </div>
        <select name="filtro_status_barbeiro" class="modern-select">
            <option value="">Todos os status</option>
            <option value="ativo" <?= ($filtro_status_barbeiro ?? '') === 'ativo' ? 'selected' : '' ?>>Somente ativos</option>
            <option value="inativo" <?= ($filtro_status_barbeiro ?? '') === 'inativo' ? 'selected' : '' ?>>Somente inativos</option>
        </select>
        <button type="submit" class="btn-modern-filter" title="Aplicar filtros"><i class="fa fa-search"></i></button>
        <?php if (($busca_barbeiro ?? '') !== '' || ($filtro_status_barbeiro ?? '') !== ''): ?>
            <a href="admin.php?tab=barbeiros" class="btn-clear-barbeiro" title="Limpar filtros"><i class="fa fa-times"></i></a>
        <?php endif; ?>
    </form>

    <?php if (($busca_barbeiro ?? '') !== '' || ($filtro_status_barbeiro ?? '') !== ''): ?>
        <p class="barbeiro-results-count">
            <strong><?= (int)($barbeiros_totalItems ?? 0) ?></strong> profissional(is) encontrado(s).
        </p>
    <?php endif; ?>

    <div class="modern-card-grid">
        <?php if (empty($barbeirosPaginados)): ?>
            <div class="empty-state">
                <i class="fa fa-user-slash"></i>
                <?= (($busca_barbeiro ?? '') !== '' || ($filtro_status_barbeiro ?? '') !== '')
                    ? 'Nenhum profissional corresponde aos filtros aplicados.'
                    : 'Nenhum profissional cadastrado.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($barbeirosPaginados as $id => $b): ?>
                <?php
                    $status_barbeiro = $b['status'] ?? 'ativo';
                    if(empty($status_barbeiro)) $status_barbeiro = 'ativo';
                    $is_inativo = ($status_barbeiro === 'inativo');
                    
                    // CORREÇÃO: Foto do Barbeiro na listagem
                    $foto_barbeiro = (!empty(trim($b['foto'] ?? '')) && file_exists(trim($b['foto']))) ? trim($b['foto']) : 'uploads/default-profile.jpg';

                    // Folgas & férias vigentes deste profissional (para o modal de gestão)
                    $ausenciasBarbeiro = function_exists('getAusenciasBarbeiro') ? getAusenciasBarbeiro($id, true) : [];
                    $ausenciasJson = [];
                    foreach ($ausenciasBarbeiro as $aus) {
                        $ini = date('d/m/Y', strtotime($aus['data_inicio']));
                        $fim = date('d/m/Y', strtotime($aus['data_fim']));
                        $ausenciasJson[] = [
                            'id' => $aus['id'],
                            'periodo' => ($aus['data_inicio'] === $aus['data_fim']) ? $ini : ($ini . ' até ' . $fim),
                            'tipo_label' => rotuloTipoAusencia($aus['tipo'] ?? 'folga'),
                            'motivo' => $aus['motivo'] ?? '',
                        ];
                    }
                ?>
                <div class="modern-card" style="<?= $is_inativo ? 'opacity: 0.7; filter: grayscale(40%);' : '' ?>"> 
                    
                    <div class="modern-card-header">
                        <div class="card-barbeiro-info">
                            <img src="<?= htmlspecialchars($foto_barbeiro) ?>" alt="<?= htmlspecialchars($b['nome']) ?>" class="card-avatar">
                            <div>
                                <h4 class="card-title">
                                    <?= htmlspecialchars($b['nome']) ?>
                                    <?php if ($is_inativo): ?>
                                        <span class="badge-modern badge-red">INATIVO</span>
                                    <?php else: ?>
                                        <span class="badge-modern badge-green">ATIVO</span>
                                    <?php endif; ?>
                                </h4>
                            </div>
                        </div>
                    </div>

                    <div class="modern-card-body">
                        <div class="card-stats-grid">
                            <div class="card-stat-item stat-concluidos">
                                <i class="fa fa-check-double"></i>
                                <span class="card-stat-label">Concluídos</span>
                                <span class="card-stat-value"><?= $estatisticas_barbeiros[$id]['concluidos'] ?? 0 ?></span>
                            </div>
                            <div class="card-stat-item stat-receita">
                                <i class="fa fa-dollar-sign"></i>
                                <span class="card-stat-label">Receita</span>
                                <span class="card-stat-value" style="font-size: 0.95rem;">R$ <?= number_format($estatisticas_barbeiros[$id]['receita'] ?? 0, 2, ',', '.') ?></span>
                            </div>
                            <div class="card-stat-item stat-media">
                                <i class="fa fa-star"></i>
                                <span class="card-stat-label">Média</span>
                                <span class="card-stat-value"><?= $estatisticas_barbeiros[$id]['media_avaliacoes'] ?? '0' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="modern-card-footer">
                        <button class="btn-action text-btn btn-primary-action" data-modal-target="#modal-barbeiro-detalhes" data-id="<?= $id ?>" title="Ver Detalhes/Histórico">
                            <i class="fa fa-list-alt"></i> Detalhes
                        </button>
                        
                        <button class="btn-action btn-info-action" data-modal-target="#modal-bloqueio-horarios" data-id="<?= $id ?>" data-nome="<?= htmlspecialchars($b['nome']) ?>" title="Bloquear Agenda Diária">
                            <i class="fa fa-calendar-times"></i>
                        </button>

                        <button class="btn-action btn-info-action" data-modal-target="#modal-ausencias" data-type="ausencias" data-id="<?= $id ?>" data-nome="<?= htmlspecialchars($b['nome']) ?>" data-ausencias="<?= htmlspecialchars(json_encode($ausenciasJson, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>" title="Folgas & Férias<?= count($ausenciasJson) ? ' (' . count($ausenciasJson) . ')' : '' ?>">
                            <i class="fa fa-umbrella-beach"></i><?= count($ausenciasJson) ? ' ' . count($ausenciasJson) : '' ?>
                        </button>

                        <button class="btn-action btn-dark" data-modal-target="#modal-barbeiro" data-id="<?= $b['id'] ?>" data-type="barbeiro" title="Editar Profissional">
                            <i class="fa fa-edit"></i>
                        </button>
                        
                        <a href="?action=excluir_barbeiro&id=<?= $b['id'] ?>&csrf_token=<?= $csrf_token ?>" class="btn-action btn-danger" onclick="return confirm('Tem certeza que deseja excluir permanentemente este barbeiro?')" title="Excluir">
                            <i class="fa fa-trash"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($barbeiros_totalPages > 1): ?>
    <div class="modern-pagination">
        <?php for ($i = 1; $i <= $barbeiros_totalPages; $i++): ?>
            <a href="?<?= http_build_query(['tab' => 'barbeiros', 'busca_barbeiro' => $busca_barbeiro ?? '', 'filtro_status_barbeiro' => $filtro_status_barbeiro ?? '', 'bar_page' => $i]) ?>" class="<?= ($i == $barbeiros_currentPage ? 'current' : '') ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <div class="config-schedule-section">
        <div class="config-schedule-header">
            <h3><i class="fa fa-clock"></i> Grade de Horários Fixa</h3>
        </div>

        <div class="form-group">
            <label>Selecione o Profissional para Configurar:</label>
            <div class="custom-select-wrapper">
                <i class="fa fa-user-tie"></i>
                <select id="select-barbeiro-grade">
                    <option value="">-- Selecione o barbeiro --</option>
                    <?php foreach($barbeirosArr as $id => $b): ?>
                        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($b['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="container-grade-horarios" style="display: none;">
            <form method="POST" action="admin.php">
                <input type="hidden" name="action" value="salvar_semana_horarios">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="barbeiro_id" id="input_barbeiro_id_grade">

                <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
                    <button type="button" id="btn-replicar-horarios" class="btn-replicar">
                        <i class="fa fa-copy"></i> Copiar horários de Segunda para a Semana Inteira
                    </button>
                </div>

                <div class="modern-table-wrapper" style="box-shadow: none;">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th style="text-align: center; width: 80px;">Ativo</th>
                                <th>Dia da Semana</th>
                                <th>Horário de Entrada</th>
                                <th>Horário de Saída</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $ordemDias = [1, 2, 3, 4, 5, 6, 0]; // Seg a Dom
                            foreach($ordemDias as $diaNum): 
                                $diaNome = $dias_semana_texto[$diaNum];
                            ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="horarios[<?= $diaNum ?>][ativo]" id="dia_ativo_<?= $diaNum ?>" value="1" class="custom-checkbox">
                                    <label for="dia_ativo_<?= $diaNum ?>" class="day-label" style="display: none;"><?= $diaNome ?></label>
                                </td>
                                <td>
                                    <label for="dia_ativo_<?= $diaNum ?>" class="day-label"><?= $diaNome ?></label>
                                </td>
                                <td data-label="Entrada">
                                    <input type="time" name="horarios[<?= $diaNum ?>][inicio]" class="time-input time-input-start" id="inicio_<?= $diaNum ?>">
                                </td>
                                <td data-label="Saída">
                                    <input type="time" name="horarios[<?= $diaNum ?>][fim]" class="time-input time-input-end" id="fim_<?= $diaNum ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn-save-grade">
                    <i class="fa fa-save"></i> Salvar Grade Completa
                </button>
            </form>
        </div>
        
        <div id="aviso-selecao-grade" style="text-align: center; padding: 40px; color: #94a3b8; font-style: italic;">
            <i class="fa fa-arrow-up" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i> 
            Selecione um profissional acima para visualizar e editar sua grade de horários semanais.
        </div>
    </div>

</div>

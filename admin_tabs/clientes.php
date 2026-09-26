<?php
// Aba administrativa de clientes. Os dados e filtros são preparados em admin_data.php.
$csrf_token = generate_csrf_token();

$clientes_filtros_ativos = $busca_cliente !== ''
    || $filtro_status_cliente !== ''
    || $filtro_assinatura_cliente !== ''
    || $ordenar_cliente !== 'nome';
$clientes_inicio = $clientes_totalItems > 0 ? (($clientes_currentPage - 1) * $clientes_itemsPerPage) + 1 : 0;
$clientes_fim = min($clientes_currentPage * $clientes_itemsPerPage, $clientes_totalItems);
$clientes_query_base = http_build_query([
    'tab' => 'clientes',
    'busca_cliente' => $busca_cliente,
    'filtro_status_cliente' => $filtro_status_cliente,
    'filtro_assinatura_cliente' => $filtro_assinatura_cliente,
    'ordenar_cliente' => $ordenar_cliente,
]);
?>

<style>
    /* Componentes compartilhados (cabeçalho, cards, badges, paginação,
       botões de ação, estado vazio) vivem em css/admin_components.css */
    .clientes-layout { display: flex; flex-direction: column; gap: 18px; animation: fadeIn .4s ease-out; }

    /* Resumo em KPIs -- reaproveita .kpi-grid/.kpi-card (mesmo componente
       usado em Dashboard e Avaliações), só as cores de ícone são próprias. */
    .icon-cadastrados { background: #eff6ff; color: #3b82f6; }
    .icon-ativos { background: #ecfdf5; color: #10b981; }
    .icon-assinantes { background: #fdf4ff; color: #a855f7; }
    .icon-sem-retorno { background: #fffbeb; color: #f59e0b; }

    /* Barra de busca/filtro -- mesmo padrão usado em Barbeiros e
       Serviços & Estoque (histórico). */
    .client-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .client-search-control { position: relative; flex: 1 1 240px; min-width: 0; }
    .client-search-control > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
    .client-toolbar .filter-input { width: 100%; box-sizing: border-box; height: 44px; padding: 0 14px 0 40px; border: 1px solid #d8e0ea; border-radius: 8px; outline: none; background: #fff; color: #27364b; font: 600 .88rem 'Inter', sans-serif; transition: border-color .2s, box-shadow .2s; }
    .client-toolbar .filter-input::placeholder { color: #94a3b8; font-weight: 500; }
    .client-toolbar .filter-input:focus { border-color: var(--secondary-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--secondary-color) 11%, transparent); }
    .client-toolbar .modern-select { flex: 0 1 165px; min-width: 145px; height: 44px; padding: 0 14px; background: #fff; cursor: pointer; }
    .client-toolbar .btn-modern-filter { width: 44px; height: 44px; min-height: 44px; padding: 0; flex-shrink: 0; }
    .btn-clear-filter { width: 44px; height: 44px; flex-shrink: 0; border: 1px solid #d8e0ea; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #64748b; background: #fff; text-decoration: none; transition: border-color .2s, color .2s, background .2s; }
    .btn-clear-filter:hover { color: #dc2626; border-color: #fecaca; background: #fff7f7; }

    .client-results-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .client-results-count { color: #64748b; font-size: .84rem; }
    .client-results-count strong { color: #1e293b; }
    .client-view-switch { display: inline-flex; padding: 3px; border: 1px solid #dbe3ed; border-radius: 7px; background: #fff; }
    .client-view-btn { width: 34px; height: 30px; border: 0; border-radius: 5px; background: transparent; color: #94a3b8; cursor: pointer; }
    .client-view-btn.is-active { background: var(--secondary-color); color: #fff; }

    .card-client-content { min-width: 0; }
    .card-client-contact { min-width: 0; display: grid; gap: 3px; color: #64748b; font-size: .78rem; }
    .card-client-contact span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .card-client-contact i { width: 15px; color: #94a3b8; }

    .client-plan-strip { margin-bottom: 14px; padding: 10px 11px; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #f8fafc; border-left: 3px solid var(--secondary-color); border-radius: 6px; }
    .client-plan-strip small { display: block; color: #64748b; font-size: .66rem; font-weight: 700; text-transform: uppercase; }
    .client-plan-strip strong { display: block; margin-top: 2px; color: #1e293b; font-size: .84rem; overflow-wrap: anywhere; }
    .client-plan-provider { color: #64748b; font-size: .68rem; }
    .client-plan-expiry { flex-shrink: 0; text-align: right; }
    .client-plan-expiry strong { color: var(--secondary-color); }
    .client-plan-expiry.is-urgent strong { color: #dc2626; }

    /* Rodapé do card de cliente alinha as ações à direita */
    .modern-card-footer .action-buttons { width: 100%; justify-content: flex-end; }

    .modern-card-grid.is-list { grid-template-columns: 1fr; gap: 9px; }
    .modern-card-grid.is-list .modern-card { display: grid; grid-template-columns: minmax(260px, 1.05fr) minmax(430px, 1.8fr) auto; align-items: stretch; }
    .modern-card-grid.is-list .modern-card-header { display: flex; align-items: center; border-right: 1px solid #f1f5f9; border-bottom: 0; }
    .modern-card-grid.is-list .modern-card-body { display: flex; align-items: center; gap: 14px; }
    .modern-card-grid.is-list .client-plan-strip { width: min(240px, 42%); margin: 0; flex-shrink: 0; }
    .modern-card-grid.is-list .card-stats-grid { flex: 1; }
    .modern-card-grid.is-list .modern-card-footer { display: flex; align-items: center; border-top: 0; border-left: 1px solid #f1f5f9; }

    @media (max-width: 1180px) {
        .modern-card-grid.is-list .modern-card { display: flex; }
        .modern-card-grid.is-list .modern-card-header, .modern-card-grid.is-list .modern-card-footer { border-right: 0; border-left: 0; }
        .modern-card-grid.is-list .modern-card-body { display: block; }
        .modern-card-grid.is-list .client-plan-strip { width: auto; margin-bottom: 14px; }
    }

    @media (max-width: 768px) {
        .client-toolbar { flex-direction: column; align-items: stretch; padding: 12px; }
        .client-toolbar .modern-select { flex-basis: auto; width: 100%; }
        .client-toolbar .btn-modern-filter, .btn-clear-filter { width: 100%; }
        .client-view-switch { display: none; }
        .modern-card-footer .action-buttons { justify-content: flex-start; }
    }
</style>

<div class="clientes-layout">
    <div class="dash-header-bar">
        <div class="dash-title">
            <h3>Gerenciar Clientes</h3>
            <p>Acompanhe relacionamento, recorrência e assinaturas em um só lugar.</p>
        </div>
        <button type="button" class="btn-modern btn-modern-add" data-modal-target="#modal-cliente">
            <i class="fa fa-user-plus"></i> Novo Cliente
        </button>
    </div>

    <div class="kpi-grid" aria-label="Resumo da carteira de clientes">
        <div class="kpi-card">
            <div class="kpi-icon icon-cadastrados"><i class="fa fa-users"></i></div>
            <div class="kpi-info">
                <h4>Cadastrados</h4>
                <div class="value"><?= (int)$clientes_resumo['total'] ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-ativos"><i class="fa fa-user-check"></i></div>
            <div class="kpi-info">
                <h4>Contas ativas</h4>
                <div class="value"><?= (int)$clientes_resumo['ativos'] ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-assinantes"><i class="fa fa-crown"></i></div>
            <div class="kpi-info">
                <h4>Assinantes</h4>
                <div class="value"><?= (int)$clientes_resumo['assinantes'] ?></div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon icon-sem-retorno"><i class="fa fa-user-clock"></i></div>
            <div class="kpi-info">
                <h4>Sem retorno 90+ dias</h4>
                <div class="value"><?= (int)$clientes_resumo['sem_retorno'] ?></div>
            </div>
        </div>
        <?php
        $aniversariantesHoje = 0;
        $mdHoje = date('m-d');
        foreach (($clientesArr ?? []) as $cAniv) {
            $nasc = (string)($cAniv['data_nascimento'] ?? '');
            if ($nasc === '') continue;
            $ts = strtotime($nasc);
            if ($ts !== false && date('m-d', $ts) === $mdHoje) $aniversariantesHoje++;
        }
        ?>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f5f3ff; color:#7c3aed;"><i class="fa fa-cake-candles"></i></div>
            <div class="kpi-info">
                <h4>Aniversariantes hoje</h4>
                <div class="value" style="<?= $aniversariantesHoje > 0 ? 'color:#7c3aed;' : '' ?>"><?= (int)$aniversariantesHoje ?></div>
            </div>
        </div>
    </div>

    <form method="GET" class="client-toolbar">
        <input type="hidden" name="tab" value="clientes">
        <div class="client-search-control">
            <i class="fa fa-search"></i>
            <input id="busca_cliente" type="search" name="busca_cliente" class="filter-input" placeholder="Nome, telefone, e-mail ou ID" value="<?= htmlspecialchars($busca_cliente) ?>">
        </div>
        <select name="filtro_status_cliente" class="modern-select" title="Status" aria-label="Filtrar por status">
            <option value="">Todos os status</option>
            <option value="ativo" <?= $filtro_status_cliente === 'ativo' ? 'selected' : '' ?>>Ativos</option>
            <option value="inativo" <?= $filtro_status_cliente === 'inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>
        <select name="filtro_assinatura_cliente" class="modern-select" title="Assinatura" aria-label="Filtrar por assinatura">
            <option value="">Todas as assinaturas</option>
            <option value="assinante" <?= $filtro_assinatura_cliente === 'assinante' ? 'selected' : '' ?>>Assinantes</option>
            <option value="sem_assinatura" <?= $filtro_assinatura_cliente === 'sem_assinatura' ? 'selected' : '' ?>>Sem assinatura</option>
            <option value="cancelamento" <?= $filtro_assinatura_cliente === 'cancelamento' ? 'selected' : '' ?>>Cancelamento agendado</option>
        </select>
        <select name="ordenar_cliente" class="modern-select" title="Ordenar por" aria-label="Ordenar por">
            <option value="nome" <?= $ordenar_cliente === 'nome' ? 'selected' : '' ?>>Nome (A-Z)</option>
            <option value="gasto" <?= $ordenar_cliente === 'gasto' ? 'selected' : '' ?>>Maior gasto</option>
            <option value="visitas" <?= $ordenar_cliente === 'visitas' ? 'selected' : '' ?>>Mais visitas</option>
            <option value="pontos" <?= $ordenar_cliente === 'pontos' ? 'selected' : '' ?>>Mais pontos</option>
            <option value="recente" <?= $ordenar_cliente === 'recente' ? 'selected' : '' ?>>Visita mais recente</option>
        </select>
        <button type="submit" class="btn-modern btn-modern-filter" title="Aplicar filtros" aria-label="Aplicar filtros">
            <i class="fa fa-filter"></i>
        </button>
        <?php if ($clientes_filtros_ativos): ?>
            <a href="?tab=clientes" class="btn-clear-filter" title="Limpar filtros" aria-label="Limpar filtros"><i class="fa fa-times"></i></a>
        <?php endif; ?>
    </form>

    <div class="client-results-bar">
        <span class="client-results-count">
            <strong><?= (int)$clientes_totalItems ?></strong> cliente<?= $clientes_totalItems === 1 ? '' : 's' ?>
            <?php if ($clientes_totalItems > 0): ?>
                · exibindo <?= (int)$clientes_inicio ?>–<?= (int)$clientes_fim ?>
            <?php endif; ?>
        </span>
        <div class="client-view-switch" role="group" aria-label="Modo de visualização">
            <button type="button" class="client-view-btn is-active" data-client-view="grid" title="Visualização em grade" aria-label="Visualização em grade"><i class="fa fa-th-large"></i></button>
            <button type="button" class="client-view-btn" data-client-view="list" title="Visualização em lista" aria-label="Visualização em lista"><i class="fa fa-list"></i></button>
        </div>
    </div>

    <div class="modern-card-grid" id="clientes-card-grid">
        <?php if (empty($clientesPaginados)): ?>
            <div class="empty-state">
                <i class="fa fa-users-slash"></i>
                <h3 style="margin:0 0 5px; color:#1e293b;">Nenhum cliente encontrado</h3>
                <p style="margin:0;">Ajuste os filtros ou cadastre um novo cliente.</p>
            </div>
        <?php else: ?>
            <?php foreach ($clientesPaginados as $id => $c):
                $foto_cliente = (!empty(trim($c['foto_perfil'] ?? '')) && file_exists(trim($c['foto_perfil'])))
                    ? trim($c['foto_perfil'])
                    : 'uploads/default-profile.jpg';
                $stats = $estatisticas_clientes[$id] ?? [
                    'gasto_total' => 0,
                    'total_agendamentos' => 0,
                    'pontos_fidelidade' => 0,
                    'ultima_visita' => 'Nunca',
                    'ultima_visita_data' => '',
                ];

                $status_cliente = !empty($c['status']) ? $c['status'] : 'ativo';
                $is_inativo = $status_cliente === 'inativo';
                $toggle_link = '?action=toggle_cliente_status&id=' . urlencode((string)$c['id'])
                    . '&tab=clientes&csrf_token=' . urlencode($csrf_token);
                $toggle_texto = $is_inativo ? 'Ativar conta' : 'Inativar conta';
                $toggle_icon = $is_inativo ? 'fa-check' : 'fa-ban';
                $toggle_class = $is_inativo ? 'btn-success' : 'btn-warning';

                $assinatura = getAssinaturaCliente($id);
                $is_assinante = $assinatura
                    && in_array($assinatura['status'] ?? '', ['ativo', 'cancelamento_agendado'], true)
                    && strtotime($assinatura['data_fim'] ?? '') >= strtotime('today');
                $cancelamento_agendado = $is_assinante && ($assinatura['status'] ?? '') === 'cancelamento_agendado';
                $dias_restantes = 0;
                $plano_nome = '';
                $gateway_nome = '';

                if ($is_assinante) {
                    $dias_restantes = max(0, (int)ceil((strtotime($assinatura['data_fim']) - time()) / 86400));
                    $plano_nome = $planosArr[$assinatura['plano_id']]['nome'] ?? 'Plano de Assinatura';
                    $gateway = $assinatura['gateway'] ?? 'manual';
                    $gateway_nome = $gateway === 'stripe' ? 'Stripe' : 'Manual';
                }

                $ultima_visita_data = $stats['ultima_visita_data'] ?? '';
                $cliente_sem_retorno = $ultima_visita_data !== '' && strtotime($ultima_visita_data) <= strtotime('-90 days');
                $whatsapp_numero = preg_replace('/\D+/', '', (string)($c['telefone'] ?? ''));
                if ($whatsapp_numero !== '' && strlen($whatsapp_numero) <= 11) {
                    $whatsapp_numero = '55' . $whatsapp_numero;
                }
            ?>
                <article class="modern-card <?= $is_inativo ? 'is-inactive' : '' ?>">
                    <div class="modern-card-header">
                        <div class="card-client-info">
                            <img src="<?= htmlspecialchars($foto_cliente) ?>" alt="Foto de <?= htmlspecialchars($c['nome']) ?>" class="card-avatar">
                            <div class="card-client-content">
                                <h4 class="card-client-name">
                                    <?= htmlspecialchars($c['nome']) ?>
                                    <?php if ($is_inativo): ?>
                                        <span class="badge-modern badge-red">Inativo</span>
                                    <?php elseif ($is_assinante): ?>
                                        <span class="badge-modern badge-green"><i class="fa fa-crown"></i> Assinante</span>
                                    <?php endif; ?>
                                    <?php if ($cliente_sem_retorno): ?>
                                        <span class="badge-modern badge-warning-soft"><i class="fa fa-clock"></i> 90+ dias</span>
                                    <?php endif; ?>
                                </h4>
                                <div class="card-client-contact">
                                    <span><i class="fa fa-fingerprint"></i> <?= htmlspecialchars($c['id']) ?></span>
                                    <span><i class="fa fa-phone"></i> <?= htmlspecialchars($c['telefone'] ?? 'Não informado') ?></span>
                                    <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($c['email'] ?? 'Não informado') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modern-card-body">
                        <?php if ($is_assinante):
                            $data_fim_fmt = !empty($assinatura['data_fim']) ? date('d/m/Y', strtotime($assinatura['data_fim'])) : '—';
                        ?>
                            <div class="client-plan-strip">
                                <div>
                                    <small><?= $cancelamento_agendado ? 'Benefícios até o vencimento' : 'Plano atual' ?></small>
                                    <strong><?= htmlspecialchars($plano_nome) ?></strong>
                                    <span class="client-plan-provider"><?= htmlspecialchars($gateway_nome) ?> · <?= $cancelamento_agendado ? 'até' : 'válido até' ?> <?= $data_fim_fmt ?></span>
                                </div>
                                <div class="client-plan-expiry <?= $dias_restantes <= 5 ? 'is-urgent' : '' ?>">
                                    <small><?= $cancelamento_agendado ? 'Encerra em' : 'Renova em' ?></small>
                                    <strong><?= (int)$dias_restantes ?> dias</strong>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="card-stats-grid">
                            <div class="card-stat-item destaque">
                                <i class="fa fa-dollar-sign"></i>
                                <span class="card-stat-label">Gasto total</span>
                                <span class="card-stat-value">R$ <?= number_format((float)$stats['gasto_total'], 2, ',', '.') ?></span>
                            </div>
                            <div class="card-stat-item">
                                <i class="fa fa-calendar-check"></i>
                                <span class="card-stat-label">Visitas</span>
                                <span class="card-stat-value"><?= (int)$stats['total_agendamentos'] ?> concl.</span>
                            </div>
                            <div class="card-stat-item">
                                <i class="fa fa-star" style="color:#f59e0b;"></i>
                                <span class="card-stat-label">Fidelidade</span>
                                <span class="card-stat-value"><?= (int)$stats['pontos_fidelidade'] ?> pts</span>
                            </div>
                            <div class="card-stat-item">
                                <i class="fa fa-history"></i>
                                <span class="card-stat-label">Última visita</span>
                                <span class="card-stat-value"><?= htmlspecialchars($stats['ultima_visita']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="modern-card-footer">
                        <div class="action-buttons">
                            <button type="button" class="btn-action text-btn btn-primary-action" data-modal-target="#modal-cliente-detalhes" data-id="<?= htmlspecialchars($c['id']) ?>" title="Ver histórico completo">
                                <i class="fa fa-list-alt"></i> Detalhes
                            </button>
                            <?php if ($whatsapp_numero !== ''): ?>
                                <a href="https://wa.me/<?= htmlspecialchars($whatsapp_numero) ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-whatsapp" title="Conversar pelo WhatsApp" aria-label="Conversar com <?= htmlspecialchars($c['nome']) ?> pelo WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn-action btn-dark" data-modal-target="#modal-cliente" data-id="<?= htmlspecialchars($c['id']) ?>" data-type="cliente" title="Editar cliente" aria-label="Editar <?= htmlspecialchars($c['nome']) ?>">
                                <i class="fa fa-edit"></i>
                            </button>
                            <a href="<?= htmlspecialchars($toggle_link) ?>" class="btn-action <?= htmlspecialchars($toggle_class) ?>" onclick="return confirm('Tem certeza que deseja alterar o status deste cliente? Clientes inativos não conseguem fazer login.')" title="<?= htmlspecialchars($toggle_texto) ?>" aria-label="<?= htmlspecialchars($toggle_texto) ?>">
                                <i class="fa <?= htmlspecialchars($toggle_icon) ?>"></i>
                            </a>
                            <a href="?action=excluir_cliente&id=<?= urlencode((string)$c['id']) ?>&tab=clientes&csrf_token=<?= urlencode($csrf_token) ?>" class="btn-action btn-danger" onclick="return confirm('ATENÇÃO: excluir um cliente apagará todo o histórico dele (agendamentos, notas e fidelidade). Esta ação é irreversível. Deseja continuar?')" title="Excluir permanentemente" aria-label="Excluir <?= htmlspecialchars($c['nome']) ?>">
                                <i class="fa fa-trash"></i>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($clientes_totalPages > 1): ?>
        <nav class="modern-pagination" aria-label="Paginação de clientes">
            <?php for ($i = 1; $i <= $clientes_totalPages; $i++): ?>
                <a href="?<?= htmlspecialchars($clientes_query_base . '&cli_page=' . $i) ?>" class="<?= $i === $clientes_currentPage ? 'current' : '' ?>" <?= $i === $clientes_currentPage ? 'aria-current="page"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</div>

<script>
    (function () {
        var grid = document.getElementById('clientes-card-grid');
        var buttons = document.querySelectorAll('[data-client-view]');
        if (!grid || !buttons.length) {
            return;
        }

        function applyView(view) {
            var isList = view === 'list';
            grid.classList.toggle('is-list', isList);
            buttons.forEach(function (button) {
                var active = button.getAttribute('data-client-view') === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        var savedView = localStorage.getItem('admin_clientes_view') || 'grid';
        applyView(savedView);

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var view = button.getAttribute('data-client-view');
                localStorage.setItem('admin_clientes_view', view);
                applyView(view);
            });
        });
    })();
</script>
